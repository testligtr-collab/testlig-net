<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\PhoneVerificationClaim;
use App\Entity\User;
use App\Enum\PhoneVerificationFailureReason;
use App\Enum\PhoneVerificationPurpose;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\PhoneNormalizationException;
use App\Exception\PhoneVerificationException;
use App\Repository\PhoneVerificationClaimRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Issues and verifies bind_phone claims; binds verified phones atomically (Stage 2.22.2b).
 *
 * Does not generate OTP or send SMS — callers supply a short-lived plain OTP.
 */
final class PhoneVerificationClaimManager
{
    private const UNIQUE_NORMALIZED_PHONE = 'uniq_users_normalized_phone';

    private const OTP_PATTERN = '/^[0-9]{6}$/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly PhoneVerificationClaimRepository $claims,
        private readonly PhoneNormalizer $phoneNormalizer,
        private readonly PhoneOtpDigestHasher $otpDigestHasher,
        private readonly ClockInterface $clock,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Persist a new bind_phone claim. Plain OTP is never stored.
     */
    public function issue(
        User $user,
        string $phone,
        #[\SensitiveParameter]
        string $otp,
    ): PhoneVerificationClaim {
        $this->assertPersistedUser($user);
        $this->assertOtpFormat($otp);

        try {
            $pair = $this->phoneNormalizer->normalizePair($phone);
        } catch (PhoneNormalizationException) {
            throw PhoneVerificationException::invalidInput();
        }

        $userId = $user->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use ($userId, $pair, $otp): PhoneVerificationClaim {
                $lockedUser = $this->users->findOneByIdForUpdate($userId);
                if (!$lockedUser instanceof User) {
                    throw PhoneVerificationException::claimUnavailable();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $revokedCount = $this->revokeOpenClaims($lockedUser, PhoneVerificationPurpose::BindPhone, $now);

                $claimId = new UuidV7();
                $digest = $this->otpDigestHasher->hash(
                    PhoneVerificationPurpose::BindPhone,
                    $claimId,
                    $pair['normalizedPhone'],
                    $otp,
                );

                $claim = PhoneVerificationClaim::createPending(
                    user: $lockedUser,
                    purpose: PhoneVerificationPurpose::BindPhone,
                    targetPhone: $pair['phone'],
                    targetNormalizedPhone: $pair['normalizedPhone'],
                    codeDigest: $digest,
                    pepperKeyId: $this->otpDigestHasher->getKeyId(),
                    now: $now,
                    id: $claimId,
                );

                $this->claims->save($claim, false);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::PhoneVerificationClaimCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $lockedUser,
                    subjectUser: $lockedUser,
                    metadata: [
                        'claim_id' => $claimId->toRfc4122(),
                        'purpose' => PhoneVerificationPurpose::BindPhone->value,
                        'revoked_claim_count' => $revokedCount,
                    ],
                    correlationId: $claimId->toRfc4122().':created',
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();

                return $claim;
            });
        } catch (PhoneVerificationException $exception) {
            throw $exception;
        } catch (DeadlockException|LockWaitTimeoutException $exception) {
            $this->logger->notice('Phone verification claim issue lock contention.', [
                'user_id' => $userId->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
            throw PhoneVerificationException::conflict();
        }
    }

    /**
     * Verify OTP and bind the verified phone on the calling user in one transaction.
     *
     * Wrong OTP increments failedAttemptCount and commits that change before throwing.
     */
    public function verifyAndBind(
        User $user,
        Uuid $claimId,
        #[\SensitiveParameter]
        string $otp,
    ): void {
        $this->assertPersistedUser($user);
        $this->assertOtpFormat($otp);
        $userId = $user->getId();

        try {
            /** @var array{ok: true}|array{ok: false, reason: PhoneVerificationFailureReason} $outcome */
            $outcome = $this->entityManager->wrapInTransaction(function () use ($userId, $claimId, $otp): array {
                $lockedUser = $this->users->findOneByIdForUpdate($userId);
                if (!$lockedUser instanceof User) {
                    return $this->failure(PhoneVerificationFailureReason::ClaimUnavailable);
                }

                $claim = $this->claims->findOneForUserForUpdate($lockedUser, $claimId);
                if (!$claim instanceof PhoneVerificationClaim) {
                    return $this->failure(PhoneVerificationFailureReason::ClaimUnavailable);
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());

                // Enum currently only defines bind_phone; keep the guard for later purposes.
                /* @phpstan-ignore notIdentical.alwaysFalse */
                if (PhoneVerificationPurpose::BindPhone !== $claim->getPurpose()) {
                    return $this->failure(PhoneVerificationFailureReason::ClaimUnavailable);
                }
                if ($claim->isConsumed() || $claim->isRevoked()) {
                    return $this->failure(PhoneVerificationFailureReason::ClaimUnavailable);
                }
                if ($claim->isExpired($now)) {
                    return $this->failure(PhoneVerificationFailureReason::ClaimUnavailable);
                }
                if ($claim->hasExceededFailedAttempts()) {
                    return $this->failure(PhoneVerificationFailureReason::AttemptsExceeded);
                }

                $digestMatched = true;
                try {
                    $this->otpDigestHasher->verify(
                        $claim->getCodeDigest(),
                        $claim->getPurpose(),
                        $claim->getId(),
                        $claim->getTargetNormalizedPhone(),
                        $otp,
                    );
                } catch (PhoneVerificationException) {
                    $digestMatched = false;
                }

                if (!$digestMatched) {
                    $claim->recordFailedAttempt($now);
                    $this->auditRecorder->record(new SecurityAuditContext(
                        action: SecurityAuditAction::PhoneVerificationFailed,
                        actorType: SecurityAuditActorType::User,
                        outcome: SecurityAuditOutcome::Failure,
                        actorUser: $lockedUser,
                        subjectUser: $lockedUser,
                        metadata: [
                            'claim_id' => $claim->getId()->toRfc4122(),
                            'purpose' => $claim->getPurpose()->value,
                            'reason_code' => PhoneVerificationFailureReason::InvalidOtp->value,
                            'failed_attempt_count' => $claim->getFailedAttemptCount(),
                        ],
                        correlationId: $claim->getId()->toRfc4122().':fail:'.$claim->getFailedAttemptCount(),
                        captureRequestHashes: false,
                    ), false);
                    $this->entityManager->flush();

                    if ($claim->hasExceededFailedAttempts()) {
                        return $this->failure(PhoneVerificationFailureReason::AttemptsExceeded);
                    }

                    return $this->failure(PhoneVerificationFailureReason::InvalidOtp);
                }

                $lockedUser->bindVerifiedPhone(
                    $claim->getTargetPhone(),
                    $claim->getTargetNormalizedPhone(),
                    $now,
                );
                $claim->markConsumed($now);
                $this->users->save($lockedUser, false);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::PhoneBound,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $lockedUser,
                    subjectUser: $lockedUser,
                    metadata: [
                        'claim_id' => $claim->getId()->toRfc4122(),
                        'purpose' => $claim->getPurpose()->value,
                    ],
                    correlationId: $claim->getId()->toRfc4122().':bound',
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();

                return ['ok' => true];
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (!$this->isNormalizedPhoneUniqueViolation($exception)) {
                throw $exception;
            }

            $this->logger->notice('Phone bind unique constraint race.', [
                'user_id' => $userId->toRfc4122(),
                'claim_id' => $claimId->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
            $this->recordPhoneConflictAuditBestEffort($userId, $claimId);

            throw PhoneVerificationException::phoneConflict();
        } catch (PhoneVerificationException $exception) {
            throw $exception;
        } catch (DeadlockException|LockWaitTimeoutException $exception) {
            $this->logger->notice('Phone verification verify/bind lock contention.', [
                'user_id' => $userId->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
            throw PhoneVerificationException::conflict();
        }

        if (!$outcome['ok']) {
            throw $this->exceptionForReason($outcome['reason']);
        }
    }

    /**
     * @return array{ok: false, reason: PhoneVerificationFailureReason}
     */
    private function failure(PhoneVerificationFailureReason $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }

    private function exceptionForReason(PhoneVerificationFailureReason $reason): PhoneVerificationException
    {
        return match ($reason) {
            PhoneVerificationFailureReason::InvalidInput => PhoneVerificationException::invalidInput(),
            PhoneVerificationFailureReason::InvalidOtp => PhoneVerificationException::digestMismatch(),
            PhoneVerificationFailureReason::AttemptsExceeded => PhoneVerificationException::attemptsExceeded(),
            PhoneVerificationFailureReason::PhoneConflict => PhoneVerificationException::phoneConflict(),
            PhoneVerificationFailureReason::Conflict => PhoneVerificationException::conflict(),
            PhoneVerificationFailureReason::ClaimUnavailable => PhoneVerificationException::claimUnavailable(),
        };
    }

    private function revokeOpenClaims(User $user, PhoneVerificationPurpose $purpose, \DateTimeImmutable $now): int
    {
        $open = $this->claims->findOpenForUserPurposeForUpdate($user, $purpose);
        $count = 0;
        foreach ($open as $claim) {
            $claim->markRevoked($now);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::PhoneVerificationRevoked,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $user,
                subjectUser: $user,
                metadata: [
                    'claim_id' => $claim->getId()->toRfc4122(),
                    'purpose' => $purpose->value,
                    'reason_code' => 'superseded_by_new_claim',
                ],
                correlationId: $claim->getId()->toRfc4122().':revoked',
                captureRequestHashes: false,
            ), false);
            ++$count;
        }

        return $count;
    }

    private function assertPersistedUser(User $user): void
    {
        if ($this->entityManager->contains($user)) {
            return;
        }

        if (null === $this->users->findOneById($user->getId())) {
            throw PhoneVerificationException::invalidInput();
        }
    }

    private function assertOtpFormat(#[\SensitiveParameter] string $otp): void
    {
        if (1 !== preg_match(self::OTP_PATTERN, $otp)) {
            throw PhoneVerificationException::invalidInput();
        }
    }

    private function isNormalizedPhoneUniqueViolation(UniqueConstraintViolationException $exception): bool
    {
        $message = $exception->getMessage();
        if (str_contains($message, self::UNIQUE_NORMALIZED_PHONE)) {
            return true;
        }

        $previous = $exception->getPrevious();
        if ($previous instanceof \Throwable && str_contains($previous->getMessage(), self::UNIQUE_NORMALIZED_PHONE)) {
            return true;
        }

        return false;
    }

    /**
     * After a unique-constraint rollback the claim stays open; record a failure audit if EM allows.
     */
    private function recordPhoneConflictAuditBestEffort(Uuid $userId, Uuid $claimId): void
    {
        try {
            if (!$this->entityManager->isOpen()) {
                return;
            }

            $this->entityManager->clear();
            $this->auditRecorder->resetRequestDedup();

            $this->entityManager->wrapInTransaction(function () use ($userId, $claimId): void {
                $user = $this->users->findOneById($userId);
                if (!$user instanceof User) {
                    return;
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::PhoneVerificationFailed,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Failure,
                    actorUser: $user,
                    subjectUser: $user,
                    metadata: [
                        'claim_id' => $claimId->toRfc4122(),
                        'purpose' => PhoneVerificationPurpose::BindPhone->value,
                        'reason_code' => PhoneVerificationFailureReason::PhoneConflict->value,
                    ],
                    correlationId: $claimId->toRfc4122().':phone_conflict',
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();
            });
        } catch (\Throwable $exception) {
            $this->logger->notice('Phone conflict audit could not be recorded after unique race.', [
                'user_id' => $userId->toRfc4122(),
                'claim_id' => $claimId->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Assessment\AssessmentAttemptContentPolicy;
use App\Assessment\AssessmentDeliveryAccessDecision;
use App\Assessment\AssessmentPublicationIntegrityVerifier;
use App\Attempt\Answer\AttemptAnswerEncryptor;
use App\Attempt\Answer\AttemptStudentAnswerValidator;
use App\Dto\SecurityAuditContext;
use App\Entity\Assessment;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentAttemptAnswer;
use App\Entity\AssessmentAttemptItem;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentRevision;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\AssessmentDeliveryAccessReason;
use App\Enum\AssessmentDeliveryRecipientStatus;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserStatus;
use App\Exception\AssessmentAttemptException;
use App\Exception\AssessmentException;
use App\Repository\AssessmentAttemptAnswerRepository;
use App\Repository\AssessmentAttemptRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Assessment attempt lifecycle: start, answer save, submit, expire, cancel.
 *
 * Global lock order:
 * 1. AssessmentDelivery PESSIMISTIC_WRITE + HINT_REFRESH
 * 2. AssessmentDeliveryRecipient (delivery+user) PESSIMISTIC_WRITE + HINT_REFRESH
 * 3. Institution / User / Membership fresh checks (READ/WRITE as needed)
 * 4. AssessmentAttempt PESSIMISTIC_WRITE + HINT_REFRESH (mutators)
 * 5. Answers / items persist; active_guard is owned by DB AFTER INSERT/UPDATE triggers
 * 6. Audit in same transaction; invalidate delivery auth cache only after commit (start)
 *
 * Single-active concurrency: uniq_aa_active_recipient_scope + pessimistic locks.
 * max_attempts COUNT alone is not race-safe.
 */
final class AssessmentAttemptManager
{
    public function __construct(
        private readonly AssessmentAttemptRepository $attempts,
        private readonly AssessmentAttemptAnswerRepository $answers,
        private readonly AssessmentAttemptItemMaterializer $itemMaterializer,
        private readonly AssessmentAttemptContentPolicy $contentPolicy,
        private readonly AttemptStudentAnswerValidator $answerValidator,
        private readonly AttemptAnswerEncryptor $answerEncryptor,
        private readonly AssessmentDeliveryAccessGate $accessGate,
        private readonly AssessmentPublicationIntegrityVerifier $publicationIntegrityVerifier,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function startAttempt(
        AssessmentDelivery $delivery,
        User $student,
        string $reasonCode,
    ): AssessmentAttempt {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $deliveryId = $delivery->getId();
        $studentId = $student->getId();

        try {
            $attempt = $this->entityManager->wrapInTransaction(function () use (
                $deliveryId,
                $studentId,
                $reasonCode,
            ): AssessmentAttempt {
                $lockedDelivery = $this->findFreshDelivery($deliveryId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedDelivery instanceof AssessmentDelivery) {
                    throw AssessmentAttemptException::notFound();
                }

                $recipient = $this->findFreshRecipientForUser(
                    $lockedDelivery->getId(),
                    $studentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$recipient instanceof AssessmentDeliveryRecipient) {
                    throw AssessmentAttemptException::recipientNotFound();
                }

                $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                    $lockedDelivery->getInstitution()->getId(),
                    LockMode::PESSIMISTIC_READ,
                );
                if (!$lockedInstitution instanceof Institution
                    || InstitutionStatus::Active !== $lockedInstitution->getStatus()
                ) {
                    throw AssessmentAttemptException::institutionInactive();
                }

                $users = $this->freshEntities->findFreshLockedUsers(
                    [$studentId],
                    LockMode::PESSIMISTIC_WRITE,
                );
                $freshStudent = $users[$studentId->toRfc4122()] ?? null;
                if (!$freshStudent instanceof User || UserStatus::Active !== $freshStudent->getStatus()) {
                    throw AssessmentAttemptException::userInactive();
                }
                if (null === $freshStudent->getEmailVerifiedAt()) {
                    throw AssessmentAttemptException::emailNotVerified();
                }

                $membership = $this->freshEntities->findFreshLockedMembership(
                    $recipient->getStudentMembership()->getId(),
                    LockMode::PESSIMISTIC_READ,
                );
                if (!$membership instanceof InstitutionMembership
                    || InstitutionMembershipStatus::Active !== $membership->getStatus()
                ) {
                    throw AssessmentAttemptException::membershipInactive();
                }
                if (InstitutionMembershipRole::Student !== $membership->getRole()) {
                    throw AssessmentAttemptException::membershipNotStudent();
                }
                if (!$membership->getUser()->getId()->equals($freshStudent->getId())
                    || !$membership->getInstitution()->getId()->equals($lockedInstitution->getId())
                ) {
                    throw AssessmentAttemptException::scopeMismatch();
                }
                if (AssessmentDeliveryRecipientStatus::Revoked === $recipient->getStatus()) {
                    throw AssessmentAttemptException::recipientRevoked();
                }
                if (AssessmentDeliveryStatus::Active !== $lockedDelivery->getStatus()) {
                    throw AssessmentAttemptException::deliveryNotActive();
                }

                $decision = $this->accessGate->evaluate($lockedDelivery->getId(), $freshStudent);
                if (!$decision->eligibleForAttemptCreation) {
                    throw $this->mapAccessDenial($decision);
                }

                $publication = $this->findFreshPublication(
                    $lockedDelivery->getAssessmentPublication()->getId(),
                );
                $assessment = $this->findFreshAssessment($lockedDelivery->getAssessment()->getId());
                if (!$publication instanceof AssessmentPublication || !$assessment instanceof Assessment) {
                    throw AssessmentAttemptException::publicationInvalid();
                }
                $revision = $this->findFreshRevision($publication->getAssessmentRevision()->getId());
                if (!$revision instanceof AssessmentRevision) {
                    throw AssessmentAttemptException::publicationInvalid();
                }
                try {
                    $this->publicationIntegrityVerifier->verify($publication, $assessment, $revision);
                } catch (AssessmentException) {
                    throw AssessmentAttemptException::publicationIntegrityFailed();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                if ($now < $lockedDelivery->getOpensAt()) {
                    throw AssessmentAttemptException::notOpenYet();
                }
                if ($now >= $lockedDelivery->getClosesAt()) {
                    throw AssessmentAttemptException::deliveryWindowClosed();
                }

                $attemptCount = $this->attempts->countForRecipient(
                    $lockedDelivery->getId(),
                    $recipient->getId(),
                );
                if ($attemptCount >= $lockedDelivery->getMaxAttempts()) {
                    throw AssessmentAttemptException::attemptQuotaExceeded();
                }

                if (null !== $this->attempts->findActiveForRecipient(
                    $lockedDelivery->getId(),
                    $recipient->getId(),
                )) {
                    throw AssessmentAttemptException::activeAttemptExists();
                }

                $attemptNumber = $this->attempts->findMaxAttemptNumber(
                    $lockedDelivery->getId(),
                    $recipient->getId(),
                ) + 1;

                $expiresAt = $this->computeExpiresAt(
                    $now,
                    $lockedDelivery->getClosesAt(),
                    $revision->getDurationSeconds(),
                );
                if ($expiresAt <= $now) {
                    throw AssessmentAttemptException::insufficientRemainingTime();
                }

                $attempt = AssessmentAttempt::createInProgress(
                    $lockedDelivery,
                    $recipient,
                    $attemptNumber,
                    $now,
                    $expiresAt,
                );
                $items = $this->itemMaterializer->materialize($attempt, $publication);
                if ([] === $items) {
                    throw AssessmentAttemptException::invalidInput('Attempt has no materializable items.');
                }

                $this->attempts->save($attempt, false);
                foreach ($items as $item) {
                    $this->entityManager->persist($item);
                }
                $this->entityManager->flush();

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentAttemptStarted,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshStudent,
                    subjectUser: $freshStudent,
                    metadata: [
                        'source' => 'assessment_attempt_manager',
                        'reason_code' => $reasonCode,
                        'attempt_id' => $attempt->getId()->toRfc4122(),
                        'attempt_number' => $attempt->getAttemptNumber(),
                        'delivery_id' => $lockedDelivery->getId()->toRfc4122(),
                        'recipient_id' => $recipient->getId()->toRfc4122(),
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'assessment_id' => $assessment->getId()->toRfc4122(),
                        'assessment_publication_id' => $publication->getId()->toRfc4122(),
                        'publication_number' => $publication->getPublicationNumber(),
                        'new_status' => AssessmentAttemptStatus::InProgress->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();

                return $attempt;
            });
        } catch (AssessmentAttemptException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentAttemptException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateAssessmentDelivery($deliveryId);

        return $attempt;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveAnswer(
        AssessmentAttempt $attempt,
        AssessmentAttemptItem $item,
        User $student,
        array $payload,
        int $expectedVersion,
        string $reasonCode,
    ): AssessmentAttemptAnswer {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $attemptId = $attempt->getId();
        $itemId = $item->getId();
        $studentId = $student->getId();

        try {
            /** @var AssessmentAttemptAnswer|true $result true = expired during TX (committed) */
            $result = $this->entityManager->wrapInTransaction(function () use (
                $attemptId,
                $itemId,
                $studentId,
                $payload,
                $expectedVersion,
                $reasonCode,
            ): AssessmentAttemptAnswer|true {
                $lockedAttempt = $this->attempts->findFreshAttempt($attemptId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedAttempt instanceof AssessmentAttempt) {
                    throw AssessmentAttemptException::notFound();
                }
                if (!$lockedAttempt->getUser()->getId()->equals($studentId)) {
                    throw AssessmentAttemptException::unauthorized();
                }

                $this->assertFreshStudentChain($lockedAttempt, $studentId);

                if (AssessmentAttemptStatus::InProgress !== $lockedAttempt->getStatus()) {
                    if (AssessmentAttemptStatus::Expired === $lockedAttempt->getStatus()) {
                        throw AssessmentAttemptException::attemptExpired();
                    }
                    if ($lockedAttempt->getStatus()->isTerminal()) {
                        throw AssessmentAttemptException::attemptTerminal();
                    }
                    throw AssessmentAttemptException::attemptNotInProgress();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                if ($now >= $lockedAttempt->getExpiresAt()) {
                    $this->expireLocked($lockedAttempt, 'system_expire');

                    return true;
                }

                $lockedItem = $this->findFreshAttemptItem($itemId);
                if (!$lockedItem instanceof AssessmentAttemptItem
                    || !$lockedItem->getAttempt()->getId()->equals($lockedAttempt->getId())
                ) {
                    throw AssessmentAttemptException::itemNotFound();
                }

                $questionRevision = $lockedItem->getQuestionRevision();
                $normalized = $this->answerValidator->validateAndNormalize(
                    $questionRevision->getType(),
                    $questionRevision->getId(),
                    $payload,
                );
                $encrypted = $this->answerEncryptor->encrypt(
                    $normalized,
                    $lockedAttempt->getId()->toRfc4122(),
                    $lockedItem->getId()->toRfc4122(),
                    $studentId->toRfc4122(),
                );

                $existing = $this->answers->findAnswer($lockedAttempt->getId(), $lockedItem->getId());
                if (!$existing instanceof AssessmentAttemptAnswer) {
                    if (0 !== $expectedVersion) {
                        throw AssessmentAttemptException::staleAnswerVersion();
                    }
                    $answer = AssessmentAttemptAnswer::createEncrypted(
                        $lockedAttempt,
                        $lockedItem,
                        $encrypted['ciphertext'],
                        $encrypted['nonce'],
                        $encrypted['encryptionVersion'],
                        $now,
                    );
                    $this->answers->save($answer, false);
                } else {
                    $existing->updateEncrypted(
                        $encrypted['ciphertext'],
                        $encrypted['nonce'],
                        $encrypted['encryptionVersion'],
                        $expectedVersion,
                        $now,
                    );
                    $answer = $existing;
                }

                if ($now > $lockedAttempt->getLastActivityAt()) {
                    $lockedAttempt->touchActivity($now);
                }

                $this->entityManager->flush();

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentAttemptAnswerSaved,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $lockedAttempt->getUser(),
                    subjectUser: $lockedAttempt->getUser(),
                    metadata: [
                        'source' => 'assessment_attempt_manager',
                        'reason_code' => $reasonCode,
                        'attempt_id' => $lockedAttempt->getId()->toRfc4122(),
                        'attempt_item_id' => $lockedItem->getId()->toRfc4122(),
                        'answer_version' => $answer->getClientRevision(),
                        'delivery_id' => $lockedAttempt->getDelivery()->getId()->toRfc4122(),
                        'recipient_id' => $lockedAttempt->getRecipient()->getId()->toRfc4122(),
                        'attempt_number' => $lockedAttempt->getAttemptNumber(),
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();

                return $answer;
            });
        } catch (AssessmentAttemptException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentAttemptException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        if (true === $result) {
            throw AssessmentAttemptException::attemptExpired();
        }

        return $result;
    }

    public function submit(AssessmentAttempt $attempt, User $student, string $reasonCode): void
    {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $attemptId = $attempt->getId();
        $studentId = $student->getId();

        try {
            $expiredDuringTx = $this->entityManager->wrapInTransaction(function () use (
                $attemptId,
                $studentId,
                $reasonCode,
            ): bool {
                $lockedAttempt = $this->attempts->findFreshAttempt($attemptId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedAttempt instanceof AssessmentAttempt) {
                    throw AssessmentAttemptException::notFound();
                }
                if (!$lockedAttempt->getUser()->getId()->equals($studentId)) {
                    throw AssessmentAttemptException::unauthorized();
                }

                $this->assertFreshStudentChain($lockedAttempt, $studentId);

                if (AssessmentAttemptStatus::InProgress !== $lockedAttempt->getStatus()) {
                    if (AssessmentAttemptStatus::Expired === $lockedAttempt->getStatus()) {
                        throw AssessmentAttemptException::attemptExpired();
                    }
                    if ($lockedAttempt->getStatus()->isTerminal()) {
                        throw AssessmentAttemptException::attemptTerminal();
                    }
                    throw AssessmentAttemptException::attemptNotInProgress();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                if ($now >= $lockedAttempt->getExpiresAt()) {
                    $this->expireLocked($lockedAttempt, 'system_expire');

                    return true;
                }

                $answeredCount = $this->answers->countAnsweredItems($lockedAttempt->getId());
                $unansweredRequired = $this->answers->countUnansweredRequired($lockedAttempt->getId());

                $lockedAttempt->submit($now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentAttemptSubmitted,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $lockedAttempt->getUser(),
                    subjectUser: $lockedAttempt->getUser(),
                    metadata: [
                        'source' => 'assessment_attempt_manager',
                        'reason_code' => $reasonCode,
                        'attempt_id' => $lockedAttempt->getId()->toRfc4122(),
                        'attempt_number' => $lockedAttempt->getAttemptNumber(),
                        'delivery_id' => $lockedAttempt->getDelivery()->getId()->toRfc4122(),
                        'recipient_id' => $lockedAttempt->getRecipient()->getId()->toRfc4122(),
                        'answered_item_count' => $answeredCount,
                        'unanswered_required_count' => $unansweredRequired,
                        'new_status' => AssessmentAttemptStatus::Submitted->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();

                return false;
            });
        } catch (AssessmentAttemptException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentAttemptException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        if ($expiredDuringTx) {
            throw AssessmentAttemptException::attemptExpired();
        }
    }

    public function expire(AssessmentAttempt $attempt, string $reasonCode = 'system_expire'): void
    {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $attemptId = $attempt->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use ($attemptId, $reasonCode): void {
                $lockedAttempt = $this->attempts->findFreshAttempt($attemptId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedAttempt instanceof AssessmentAttempt) {
                    throw AssessmentAttemptException::notFound();
                }
                $this->expireLocked($lockedAttempt, $reasonCode);
            });
        } catch (AssessmentAttemptException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentAttemptException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }
    }

    public function cancel(
        AssessmentAttempt $attempt,
        User $actor,
        string $reasonCode,
        string $cancellationReasonCode,
    ): void {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $cancellationReasonCode = $this->contentPolicy->normalizeCancellationReasonCode($cancellationReasonCode);
        $attemptId = $attempt->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $attemptId,
                $actorId,
                $reasonCode,
                $cancellationReasonCode,
            ): void {
                $lockedAttempt = $this->attempts->findFreshAttempt($attemptId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedAttempt instanceof AssessmentAttempt) {
                    throw AssessmentAttemptException::notFound();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_WRITE);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AssessmentAttemptException::unauthorized();
                }
                $this->assertActorMayCancel($freshActor, $lockedAttempt);

                if (AssessmentAttemptStatus::InProgress !== $lockedAttempt->getStatus()) {
                    if ($lockedAttempt->getStatus()->isTerminal()) {
                        throw AssessmentAttemptException::attemptTerminal();
                    }
                    throw AssessmentAttemptException::attemptNotInProgress();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $lockedAttempt->cancel($freshActor, $cancellationReasonCode, $now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentAttemptCancelled,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    subjectUser: $lockedAttempt->getUser(),
                    metadata: [
                        'source' => 'assessment_attempt_manager',
                        'reason_code' => $reasonCode,
                        'attempt_id' => $lockedAttempt->getId()->toRfc4122(),
                        'attempt_number' => $lockedAttempt->getAttemptNumber(),
                        'delivery_id' => $lockedAttempt->getDelivery()->getId()->toRfc4122(),
                        'recipient_id' => $lockedAttempt->getRecipient()->getId()->toRfc4122(),
                        'institution_id' => $lockedAttempt->getInstitution()->getId()->toRfc4122(),
                        'new_status' => AssessmentAttemptStatus::Cancelled->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();
            });
        } catch (AssessmentAttemptException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentAttemptException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }
    }

    private function expireLocked(AssessmentAttempt $attempt, string $reasonCode): void
    {
        if (AssessmentAttemptStatus::Expired === $attempt->getStatus()) {
            return;
        }
        if (\in_array($attempt->getStatus(), [
            AssessmentAttemptStatus::Submitted,
            AssessmentAttemptStatus::Cancelled,
        ], true)) {
            throw AssessmentAttemptException::invalidTransition();
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        if ($now < $attempt->getExpiresAt()) {
            throw AssessmentAttemptException::invalidInput('Assessment attempt has not expired yet.');
        }

        $attempt->expire($now);

        $this->auditRecorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::AssessmentAttemptExpired,
            actorType: SecurityAuditActorType::System,
            outcome: SecurityAuditOutcome::Success,
            actorUser: null,
            subjectUser: $attempt->getUser(),
            metadata: [
                'source' => 'assessment_attempt_manager',
                'reason_code' => $reasonCode,
                'attempt_id' => $attempt->getId()->toRfc4122(),
                'attempt_number' => $attempt->getAttemptNumber(),
                'delivery_id' => $attempt->getDelivery()->getId()->toRfc4122(),
                'recipient_id' => $attempt->getRecipient()->getId()->toRfc4122(),
                'new_status' => AssessmentAttemptStatus::Expired->value,
            ],
            captureRequestHashes: false,
        ), false);
        $this->entityManager->flush();
    }

    private function assertFreshStudentChain(AssessmentAttempt $attempt, Uuid $studentId): void
    {
        $users = $this->freshEntities->findFreshLockedUsers([$studentId], LockMode::PESSIMISTIC_READ);
        $freshStudent = $users[$studentId->toRfc4122()] ?? null;
        if (!$freshStudent instanceof User || UserStatus::Active !== $freshStudent->getStatus()) {
            throw AssessmentAttemptException::userInactive();
        }
        if (null === $freshStudent->getEmailVerifiedAt()) {
            throw AssessmentAttemptException::emailNotVerified();
        }

        $institution = $this->freshEntities->findFreshLockedInstitution(
            $attempt->getInstitution()->getId(),
            LockMode::PESSIMISTIC_READ,
        );
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw AssessmentAttemptException::institutionInactive();
        }

        $membership = $this->freshEntities->findFreshLockedMembership(
            $attempt->getStudentMembership()->getId(),
            LockMode::PESSIMISTIC_READ,
        );
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw AssessmentAttemptException::membershipInactive();
        }
        if (InstitutionMembershipRole::Student !== $membership->getRole()) {
            throw AssessmentAttemptException::membershipNotStudent();
        }

        $recipient = $this->findFreshRecipient($attempt->getRecipient()->getId(), LockMode::PESSIMISTIC_READ);
        if (!$recipient instanceof AssessmentDeliveryRecipient) {
            throw AssessmentAttemptException::recipientNotFound();
        }
        if (AssessmentDeliveryRecipientStatus::Revoked === $recipient->getStatus()) {
            throw AssessmentAttemptException::recipientRevoked();
        }

        $delivery = $this->findFreshDelivery($attempt->getDelivery()->getId(), LockMode::PESSIMISTIC_READ);
        if (!$delivery instanceof AssessmentDelivery) {
            throw AssessmentAttemptException::notFound();
        }
        if (AssessmentDeliveryStatus::Active !== $delivery->getStatus()) {
            throw AssessmentAttemptException::deliveryNotActive();
        }
    }

    private function assertActorMayCancel(User $actor, AssessmentAttempt $attempt): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw AssessmentAttemptException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }

        $institution = $this->freshEntities->findFreshLockedInstitution(
            $attempt->getInstitution()->getId(),
            LockMode::PESSIMISTIC_READ,
        );
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw AssessmentAttemptException::institutionInactive();
        }

        $membership = $this->freshEntities->findFreshMembershipForUser(
            $actor->getId(),
            $institution->getId(),
            LockMode::PESSIMISTIC_READ,
        );
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw AssessmentAttemptException::unauthorized();
        }

        if (!\in_array($membership->getRole(), [
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
        ], true)) {
            throw AssessmentAttemptException::unauthorized();
        }
    }

    private function computeExpiresAt(
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $closesAt,
        ?int $durationSeconds,
    ): \DateTimeImmutable {
        if (null === $durationSeconds) {
            return $closesAt;
        }
        if ($durationSeconds < 1) {
            throw AssessmentAttemptException::invalidInput('durationSeconds must be >= 1 when set.');
        }

        $candidate = $startedAt->modify(\sprintf('+%d seconds', $durationSeconds));

        return $candidate <= $closesAt ? $candidate : $closesAt;
    }

    private function mapAccessDenial(AssessmentDeliveryAccessDecision $decision): AssessmentAttemptException
    {
        return match ($decision->reason) {
            AssessmentDeliveryAccessReason::DeliveryNotFound => AssessmentAttemptException::notFound(),
            AssessmentDeliveryAccessReason::RecipientNotFound => AssessmentAttemptException::recipientNotFound(),
            AssessmentDeliveryAccessReason::RecipientRevoked => AssessmentAttemptException::recipientRevoked(),
            AssessmentDeliveryAccessReason::DeliveryNotActive => AssessmentAttemptException::deliveryNotActive(),
            AssessmentDeliveryAccessReason::NotOpenYet => AssessmentAttemptException::notOpenYet(),
            AssessmentDeliveryAccessReason::Expired => AssessmentAttemptException::deliveryWindowClosed(),
            AssessmentDeliveryAccessReason::InstitutionInactive => AssessmentAttemptException::institutionInactive(),
            AssessmentDeliveryAccessReason::UserInactive => AssessmentAttemptException::userInactive(),
            AssessmentDeliveryAccessReason::EmailNotVerified => AssessmentAttemptException::emailNotVerified(),
            AssessmentDeliveryAccessReason::MembershipInactive => AssessmentAttemptException::membershipInactive(),
            AssessmentDeliveryAccessReason::MembershipNotStudent => AssessmentAttemptException::membershipNotStudent(),
            AssessmentDeliveryAccessReason::PublicationIntegrityFailed => AssessmentAttemptException::publicationIntegrityFailed(),
            AssessmentDeliveryAccessReason::Conflict => AssessmentAttemptException::conflict(),
            AssessmentDeliveryAccessReason::Allowed => AssessmentAttemptException::conflict(),
        };
    }

    private function findFreshDelivery(Uuid $id, LockMode $lockMode): ?AssessmentDelivery
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(AssessmentDelivery::class, 'd')
            ->where('d.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode($lockMode);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentDelivery ? $result : null;
    }

    private function findFreshRecipient(Uuid $id, LockMode $lockMode): ?AssessmentDeliveryRecipient
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AssessmentDeliveryRecipient::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode($lockMode);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentDeliveryRecipient ? $result : null;
    }

    private function findFreshRecipientForUser(
        Uuid $deliveryId,
        Uuid $userId,
        LockMode $lockMode,
    ): ?AssessmentDeliveryRecipient {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AssessmentDeliveryRecipient::class, 'r')
            ->where('r.delivery = :deliveryId')
            ->andWhere('r.user = :userId')
            ->setParameter('deliveryId', $deliveryId, 'uuid')
            ->setParameter('userId', $userId, 'uuid')
            ->setMaxResults(1);
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode($lockMode);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentDeliveryRecipient ? $result : null;
    }

    private function findFreshPublication(Uuid $id): ?AssessmentPublication
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(AssessmentPublication::class, 'p')
            ->where('p.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentPublication ? $result : null;
    }

    private function findFreshAssessment(Uuid $id): ?Assessment
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof Assessment ? $result : null;
    }

    private function findFreshRevision(Uuid $id): ?AssessmentRevision
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AssessmentRevision::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentRevision ? $result : null;
    }

    private function findFreshAttemptItem(Uuid $id): ?AssessmentAttemptItem
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(AssessmentAttemptItem::class, 'i')
            ->where('i.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentAttemptItem ? $result : null;
    }

    private function mapDriverException(\Throwable $throwable): never
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof DriverException && '45000' === $current->getSQLState()) {
                throw AssessmentAttemptException::immutable();
            }
            if ($current instanceof \Doctrine\DBAL\Driver\Exception && '45000' === $current->getSQLState()) {
                throw AssessmentAttemptException::immutable();
            }
        }

        throw $throwable;
    }
}

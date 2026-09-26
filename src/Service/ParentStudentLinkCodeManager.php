<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ParentLinkCodeIssuance;
use App\Dto\SecurityAuditContext;
use App\Entity\ParentStudentLink;
use App\Entity\ParentStudentLinkActiveGuard;
use App\Entity\ParentStudentLinkCode;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Exception\ParentStudentLinkException;
use App\Repository\ParentStudentLinkActiveGuardRepository;
use App\Repository\ParentStudentLinkCodeRepository;
use App\Repository\ParentStudentLinkRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Writer for open parent-link codes and the verified links those codes create.
 * Addressed personal invitations stay on {@see ParentStudentLinkConsentManager}.
 */
final class ParentStudentLinkCodeManager
{
    public const MAX_ACTIVE_PARENTS = 4;

    public const DISPLAY_SESSION_KEY = 'parent_link_code_display';

    private const TTL = 'PT15M';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly ParentStudentLinkRepository $links,
        private readonly ParentStudentLinkActiveGuardRepository $guards,
        private readonly ParentStudentLinkCodeRepository $codes,
        private readonly ParentLinkCodeCodec $codec,
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.parent_link_code_issue')]
        private readonly RateLimiterFactory $issueLimiter,
        #[Autowire(service: 'limiter.parent_link_code_redeem')]
        private readonly RateLimiterFactory $redeemLimiter,
        #[Autowire(service: 'limiter.parent_link_code_redeem_ip')]
        private readonly RateLimiterFactory $redeemIpLimiter,
    ) {
    }

    public function issue(User $student): ParentLinkCodeIssuance
    {
        if (!$this->issueLimiter->create($student->getId()->toRfc4122())->consume(1)->isAccepted()) {
            throw ParentStudentLinkException::rateLimited();
        }

        $studentId = $student->getId();
        $plain = $this->codec->generate();

        return $this->runInTransaction(function () use ($studentId, $plain): ParentLinkCodeIssuance {
            $locked = $this->lockUsersAsc([$studentId]);
            $student = $locked[$studentId->toRfc4122()] ?? null;
            if (!$student instanceof User) {
                throw ParentStudentLinkException::unavailable();
            }
            $this->assertStudent($student);

            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            foreach ($this->codes->findUnconsumedForStudentForUpdate($studentId) as $open) {
                if ($open->isUsable($now)) {
                    $open->revoke($now);
                }
            }

            $digest = $this->hasher->hashOpenParentLinkCode($plain);
            $expiresAt = $now->add(new \DateInterval(self::TTL));
            $code = ParentStudentLinkCode::issue(
                $student,
                $digest,
                $this->hasher->getKeyId(),
                $expiresAt,
                $now,
            );
            $this->codes->save($code, false);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::LinkCodeCreated,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $student,
                subjectUser: $student,
                metadata: [
                    'source' => 'parent_link_code',
                    'link_code_id' => $code->getId()->toRfc4122(),
                ],
                correlationId: $code->getId()->toRfc4122().':created',
                captureRequestHashes: false,
            ), false);

            return new ParentLinkCodeIssuance($this->codec->display($plain), $expiresAt);
        });
    }

    public function revokeOpenCode(User $student): void
    {
        $studentId = $student->getId();
        $this->runInTransaction(function () use ($studentId): void {
            $locked = $this->lockUsersAsc([$studentId]);
            $student = $locked[$studentId->toRfc4122()] ?? null;
            if (!$student instanceof User) {
                throw ParentStudentLinkException::unavailable();
            }
            $this->assertStudent($student);
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $live = $this->codes->findLiveForStudent($studentId, $now);
            if (!$live instanceof ParentStudentLinkCode) {
                throw ParentStudentLinkException::codeRejected();
            }
            $lockedCode = $this->codes->findOneByDigestForUpdate($live->getTokenDigest());
            if (!$lockedCode instanceof ParentStudentLinkCode || !$lockedCode->isUsable($now)) {
                throw ParentStudentLinkException::codeRejected();
            }
            if (!$lockedCode->getStudent()->getId()->equals($studentId)) {
                throw ParentStudentLinkException::unauthorized();
            }
            $lockedCode->revoke($now);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::LinkCodeRevoked,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $student,
                subjectUser: $student,
                metadata: [
                    'source' => 'parent_link_code',
                    'link_code_id' => $lockedCode->getId()->toRfc4122(),
                ],
                correlationId: $lockedCode->getId()->toRfc4122().':revoked',
                captureRequestHashes: false,
            ), false);
        });
    }

    public function redeem(User $parent, string $plainCode, string $clientIp): void
    {
        $parentKey = $parent->getId()->toRfc4122();
        if (!$this->redeemLimiter->create($parentKey)->consume(1)->isAccepted()) {
            throw ParentStudentLinkException::rateLimited();
        }
        $ipKey = 'ip:'.substr($clientIp, 0, 64);
        if (!$this->redeemIpLimiter->create($ipKey)->consume(1)->isAccepted()) {
            throw ParentStudentLinkException::rateLimited();
        }

        $normalized = $this->codec->normalize($plainCode);
        if (null === $normalized) {
            $dummy = $this->hasher->hashOpenParentLinkCode(str_repeat('A', ParentLinkCodeCodec::LENGTH));
            if (!hash_equals(str_repeat('0', 64), $dummy)) {
                throw ParentStudentLinkException::codeRejected();
            }
            throw ParentStudentLinkException::codeRejected();
        }
        $digest = $this->hasher->hashOpenParentLinkCode($normalized);

        $parentId = $parent->getId();
        $outcome = $this->runInTransaction(function () use ($digest, $parentId): string {
            $preview = $this->codes->findOneByDigest($digest);
            if (!$preview instanceof ParentStudentLinkCode) {
                if (!hash_equals(str_repeat('0', 64), $digest)) {
                    throw ParentStudentLinkException::codeRejected();
                }
                throw ParentStudentLinkException::codeRejected();
            }
            if (!hash_equals($preview->getTokenDigest(), $digest) || !hash_equals($this->hasher->getKeyId(), $preview->getPepperKeyId())) {
                throw ParentStudentLinkException::codeRejected();
            }

            $studentId = $preview->getStudent()->getId();
            if ($parentId->equals($studentId)) {
                throw ParentStudentLinkException::codeRejected();
            }

            $lockedUsers = $this->lockUsersAsc([$parentId, $studentId]);
            $parent = $lockedUsers[$parentId->toRfc4122()] ?? null;
            $student = $lockedUsers[$studentId->toRfc4122()] ?? null;
            if (!$parent instanceof User || !$student instanceof User) {
                throw ParentStudentLinkException::codeRejected();
            }
            $this->assertParent($parent);
            $this->assertStudent($student);

            $code = $this->codes->findOneByDigestForUpdate($digest);
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            if (!$code instanceof ParentStudentLinkCode || !$code->isUsable($now) || !$code->getStudent()->getId()->equals($studentId)) {
                throw ParentStudentLinkException::codeRejected();
            }
            if (!hash_equals($code->getTokenDigest(), $digest)) {
                throw ParentStudentLinkException::codeRejected();
            }

            if (null !== $this->guards->findForPair($parentId, $studentId)) {
                $code->consume($now);

                return 'duplicate';
            }
            if ($this->links->countVerifiedForStudent($studentId) >= self::MAX_ACTIVE_PARENTS) {
                throw ParentStudentLinkException::parentLimitReached();
            }

            $link = ParentStudentLink::createPending(
                parent: $parent,
                student: $student,
                requestedBy: $student,
                now: $now,
            );
            $link->markVerified($parent, $now);
            $this->links->save($link, false);
            $this->guards->save(ParentStudentLinkActiveGuard::bind($link), false);
            $code->consume($now);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::ParentLinkActivated,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $parent,
                subjectUser: $student,
                metadata: [
                    'source' => 'parent_link_code',
                    'link_id' => $link->getId()->toRfc4122(),
                    'link_code_id' => $code->getId()->toRfc4122(),
                    'grants_child_data_access' => true,
                ],
                correlationId: $link->getId()->toRfc4122().':activated',
                captureRequestHashes: false,
            ), false);

            return 'ok';
        });

        if ('duplicate' === $outcome) {
            throw ParentStudentLinkException::conflict();
        }
    }

    public function revokeLink(User $student, Uuid $linkId): void
    {
        $studentId = $student->getId();
        $this->runInTransaction(function () use ($studentId, $linkId): void {
            $preview = $this->links->findOneById($linkId);
            if (!$preview instanceof ParentStudentLink || !$preview->isVerified()) {
                throw ParentStudentLinkException::unavailable();
            }
            if (!$preview->getStudent()->getId()->equals($studentId)) {
                throw ParentStudentLinkException::unauthorized();
            }
            $parentId = $preview->getParent()->getId();
            $locked = $this->lockUsersAsc([$studentId, $parentId]);
            $actor = $locked[$studentId->toRfc4122()] ?? null;
            if (!$actor instanceof User) {
                throw ParentStudentLinkException::unavailable();
            }
            $this->assertStudent($actor);

            $link = $this->links->findOneByIdForUpdate($linkId);
            if (!$link instanceof ParentStudentLink || !$link->isVerified() || !$link->getStudent()->getId()->equals($studentId)) {
                throw ParentStudentLinkException::unavailable();
            }
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $guard = $this->guards->findForPair($parentId, $studentId);
            if ($guard instanceof ParentStudentLinkActiveGuard) {
                $this->guards->remove($guard, false);
            }
            $link->markEnded($now);
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::ParentLinkRevoked,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $actor,
                subjectUser: $locked[$parentId->toRfc4122()] ?? null,
                metadata: [
                    'source' => 'parent_link_code',
                    'link_id' => $link->getId()->toRfc4122(),
                ],
                correlationId: $link->getId()->toRfc4122().':parent-revoked',
                captureRequestHashes: false,
            ), false);
        });
    }

    private function assertStudent(User $user): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($user)) {
            throw ParentStudentLinkException::unauthorized();
        }
        if (!\in_array(UserRole::Student->value, $user->getRoles(), true)) {
            throw ParentStudentLinkException::unauthorized();
        }
    }

    private function assertParent(User $user): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($user)) {
            throw ParentStudentLinkException::unauthorized();
        }
        if (!\in_array(UserRole::Parent->value, $user->getRoles(), true)) {
            throw ParentStudentLinkException::unauthorized();
        }
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, User>
     */
    private function lockUsersAsc(array $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            $unique[$id->toRfc4122()] = $id;
        }
        $sorted = array_values($unique);
        usort($sorted, static fn (Uuid $a, Uuid $b): int => $a->toRfc4122() <=> $b->toRfc4122());
        $locked = [];
        foreach ($sorted as $id) {
            $user = $this->users->findOneByIdForUpdate($id);
            if ($user instanceof User) {
                $locked[$id->toRfc4122()] = $user;
            }
        }

        return $locked;
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function runInTransaction(callable $callback): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $result = $callback();
            $this->entityManager->flush();
            $connection->commit();

            return $result;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }
            throw ParentStudentLinkException::conflict();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }
            throw $exception;
        }
    }
}

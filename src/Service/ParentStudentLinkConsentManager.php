<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ParentStudentLinkIssuanceResult;
use App\Dto\SecurityAuditContext;
use App\Entity\ParentStudentLink;
use App\Entity\ParentStudentLinkActiveGuard;
use App\Entity\PersonalInvitation;
use App\Entity\User;
use App\Enum\InvitationCodeKind;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Exception\InvitationCodeException;
use App\Exception\ParentStudentLinkException;
use App\Invitation\InvitationPurposeContract;
use App\Repository\ParentStudentLinkActiveGuardRepository;
use App\Repository\ParentStudentLinkRepository;
use App\Repository\PersonalInvitationRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Student→parent personal-invitation consent for ParentStudentLink (Stage 2.22.5b).
 *
 * Verified links still do not grant child-data access (no voter/API in this slice).
 * Plain invite codes are returned once to the caller and never persisted or audited.
 *
 * Lock order: users (UUID asc, WRITE) → personal_invitation (WRITE) → parent_student_link
 * (WRITE) → active guard mutations.
 *
 * No public controllers — delivery channel is out of band.
 */
final class ParentStudentLinkConsentManager
{
    public const INVITE_TTL = 'P7D';

    /** Raw entropy bytes before hex encoding (64 hex chars). */
    public const PLAIN_CODE_BYTES = 32;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly PersonalInvitationRepository $invitations,
        private readonly ParentStudentLinkRepository $links,
        private readonly ParentStudentLinkActiveGuardRepository $guards,
        private readonly InvitationCodeDigestHasher $digestHasher,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.parent_student_link_issue')]
        private readonly RateLimiterFactory $issueLimiter,
        #[Autowire(service: 'limiter.parent_student_link_accept')]
        private readonly RateLimiterFactory $acceptLimiter,
    ) {
    }

    /**
     * Student requests a pending link + single-use invitation for a specific parent User.
     */
    public function requestFromStudent(User $studentActor, User $parentTarget): ParentStudentLinkIssuanceResult
    {
        $this->consumeIssueLimit($studentActor->getId(), $parentTarget->getId());

        $studentId = $studentActor->getId();
        $parentId = $parentTarget->getId();

        try {
            return $this->runInTransaction(function () use ($studentId, $parentId): ParentStudentLinkIssuanceResult {
                $users = $this->lockUsersAsc([$studentId, $parentId]);
                $student = $users[$studentId->toRfc4122()] ?? null;
                $parent = $users[$parentId->toRfc4122()] ?? null;
                if (!$student instanceof User || !$parent instanceof User) {
                    throw ParentStudentLinkException::unavailable();
                }

                $this->assertStudentActor($student);
                $this->assertParentRecipient($parent);

                if (null !== $this->guards->findForPair($parentId, $studentId)) {
                    throw ParentStudentLinkException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $invitationId = new UuidV7();
                $plainCode = $this->generatePlainCode();
                $purpose = InvitationPurposeContract::PURPOSE_PARENT_LINK;
                InvitationPurposeContract::assertKnownForRedeem($purpose);

                $digest = $this->digestHasher->hash(
                    InvitationCodeKind::PersonalInvitation,
                    $invitationId,
                    $purpose,
                    $plainCode,
                );

                $invitation = PersonalInvitation::createPending(
                    createdBy: $student,
                    intendedRecipient: $parent,
                    purposeCode: $purpose,
                    codeDigest: $digest,
                    pepperKeyId: $this->digestHasher->getKeyId(),
                    expiresAt: $now->add(new \DateInterval(self::INVITE_TTL)),
                    now: $now,
                    id: $invitationId,
                );
                $this->invitations->save($invitation, false);

                $link = ParentStudentLink::createPending(
                    parent: $parent,
                    student: $student,
                    requestedBy: $student,
                    now: $now,
                    personalInvitation: $invitation,
                );
                $this->links->save($link, false);
                $this->guards->save(ParentStudentLinkActiveGuard::bind($link), false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::ParentStudentLinkRequested,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $student,
                    subjectUser: $parent,
                    metadata: [
                        'source' => 'parent_student_link_consent_manager',
                        'link_id' => $link->getId()->toRfc4122(),
                        'invitation_id' => $invitationId->toRfc4122(),
                        'purpose' => $purpose,
                    ],
                    correlationId: $link->getId()->toRfc4122().':requested',
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return new ParentStudentLinkIssuanceResult($link, $invitation, $plainCode);
            });
        } catch (ParentStudentLinkException $exception) {
            throw $exception;
        } catch (InvitationCodeException) {
            throw ParentStudentLinkException::invalidInput();
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw ParentStudentLinkException::conflict();
        }
    }

    /**
     * Parent accepts with invitation id + plain code. Verifies link; does not open child data.
     */
    public function acceptByParent(
        User $parentActor,
        Uuid $invitationId,
        #[\SensitiveParameter]
        string $plainCode,
    ): ParentStudentLink {
        $this->consumeAcceptLimit($parentActor->getId(), $invitationId);

        $parentActorId = $parentActor->getId();
        /** @var array{reason: ?string} $failure */
        $failure = ['reason' => null];

        try {
            return $this->runInTransaction(function () use ($parentActorId, $invitationId, $plainCode, &$failure): ParentStudentLink {
                $preview = $this->invitations->findOneById($invitationId);
                if (!$preview instanceof PersonalInvitation) {
                    $failure['reason'] = 'invitation_missing';
                    throw ParentStudentLinkException::unavailable();
                }

                $creatorId = $preview->getCreatedBy()->getId();
                $recipientId = $preview->getIntendedRecipient()->getId();
                $users = $this->lockUsersAsc([$parentActorId, $creatorId, $recipientId]);
                $parent = $users[$parentActorId->toRfc4122()] ?? null;
                $student = $users[$creatorId->toRfc4122()] ?? null;
                $recipient = $users[$recipientId->toRfc4122()] ?? null;
                if (!$parent instanceof User || !$student instanceof User || !$recipient instanceof User) {
                    $failure['reason'] = 'users_missing';
                    throw ParentStudentLinkException::unavailable();
                }

                if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($parent)
                    || !\in_array(UserRole::Parent->value, $parent->getRoles(), true)) {
                    $failure['reason'] = 'parent_not_eligible';
                    throw ParentStudentLinkException::unauthorized();
                }
                if (!$parent->getId()->equals($recipient->getId())) {
                    $failure['reason'] = 'recipient_mismatch';
                    throw ParentStudentLinkException::unauthorized();
                }

                $invitation = $this->invitations->findOneByIdForUpdate($invitationId);
                if (!$invitation instanceof PersonalInvitation) {
                    $failure['reason'] = 'invitation_missing';
                    throw ParentStudentLinkException::unavailable();
                }

                $purpose = InvitationPurposeContract::assertKnownForRedeem($invitation->getPurposeCode());
                if (InvitationPurposeContract::PURPOSE_PARENT_LINK !== $purpose) {
                    $failure['reason'] = 'purpose_rejected';
                    throw ParentStudentLinkException::unavailable();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                if (!$invitation->isUsable($now)) {
                    $failure['reason'] = 'invitation_unusable';
                    throw ParentStudentLinkException::unavailable();
                }

                try {
                    $this->digestHasher->verify(
                        $invitation->getCodeDigest(),
                        $invitation->getPepperKeyId(),
                        InvitationCodeKind::PersonalInvitation,
                        $invitation->getId(),
                        $purpose,
                        $plainCode,
                    );
                } catch (InvitationCodeException) {
                    $failure['reason'] = 'code_mismatch';
                    throw ParentStudentLinkException::unavailable();
                }

                $link = $this->links->findOneByPersonalInvitationIdForUpdate($invitation->getId());
                if (!$link instanceof ParentStudentLink || !$link->isPending()) {
                    $failure['reason'] = 'link_unusable';
                    throw ParentStudentLinkException::unavailable();
                }
                if (!$link->getParent()->getId()->equals($parent->getId())
                    || !$link->getStudent()->getId()->equals($student->getId())) {
                    $failure['reason'] = 'link_party_mismatch';
                    throw ParentStudentLinkException::unavailable();
                }

                if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($student)
                    || !\in_array(UserRole::Student->value, $student->getRoles(), true)) {
                    $failure['reason'] = 'student_not_eligible';
                    throw ParentStudentLinkException::unauthorized();
                }

                $invitation->markConsumed($now);
                $link->markVerified($parent, $now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::ParentStudentLinkVerified,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $parent,
                    subjectUser: $student,
                    metadata: [
                        'source' => 'parent_student_link_consent_manager',
                        'link_id' => $link->getId()->toRfc4122(),
                        'invitation_id' => $invitation->getId()->toRfc4122(),
                        'purpose' => $purpose,
                        'grants_child_data_access' => true,
                    ],
                    correlationId: $link->getId()->toRfc4122().':verified',
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $link;
            });
        } catch (ParentStudentLinkException $exception) {
            if (null !== $failure['reason']) {
                $this->auditAcceptFailure($parentActorId, $invitationId, $failure['reason']);
            }
            throw $exception;
        } catch (InvitationCodeException) {
            $this->auditAcceptFailure($parentActorId, $invitationId, 'purpose_rejected');
            throw ParentStudentLinkException::unavailable();
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw ParentStudentLinkException::conflict();
        }
    }

    /**
     * Soft-end by parent or student; removes active guard in the same transaction.
     */
    public function endByParticipant(User $actor, Uuid $linkId): void
    {
        $actorId = $actor->getId();

        try {
            $this->runInTransaction(function () use ($actorId, $linkId): void {
                $preview = $this->links->findOneById($linkId);
                if (!$preview instanceof ParentStudentLink || $preview->isEnded()) {
                    throw ParentStudentLinkException::unavailable();
                }

                $parentId = $preview->getParent()->getId();
                $studentId = $preview->getStudent()->getId();
                $inviteId = $preview->getPersonalInvitation()?->getId();

                $users = $this->lockUsersAsc([$actorId, $parentId, $studentId]);
                $actor = $users[$actorId->toRfc4122()] ?? null;
                if (!$actor instanceof User) {
                    throw ParentStudentLinkException::unavailable();
                }
                if (!$actor->getId()->equals($parentId) && !$actor->getId()->equals($studentId)) {
                    throw ParentStudentLinkException::unauthorized();
                }
                if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
                    throw ParentStudentLinkException::unauthorized();
                }

                if ($inviteId instanceof Uuid) {
                    $this->invitations->findOneByIdForUpdate($inviteId);
                }

                $link = $this->links->findOneByIdForUpdate($linkId);
                if (!$link instanceof ParentStudentLink || $link->isEnded()) {
                    throw ParentStudentLinkException::unavailable();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $guard = $this->guards->findForPair($parentId, $studentId);
                if ($guard instanceof ParentStudentLinkActiveGuard) {
                    $this->guards->remove($guard, false);
                }

                $invitation = $link->getPersonalInvitation();
                if ($invitation instanceof PersonalInvitation && $invitation->isUsable($now)) {
                    $invitation->markRevoked($now);
                }

                $link->markEnded($now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::ParentStudentLinkEnded,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $actor,
                    subjectUser: $actor->getId()->equals($parentId)
                        ? ($users[$studentId->toRfc4122()] ?? null)
                        : ($users[$parentId->toRfc4122()] ?? null),
                    metadata: [
                        'source' => 'parent_student_link_consent_manager',
                        'link_id' => $link->getId()->toRfc4122(),
                    ],
                    correlationId: $link->getId()->toRfc4122().':ended',
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (ParentStudentLinkException $exception) {
            throw $exception;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw ParentStudentLinkException::conflict();
        }
    }

    /**
     * Transaction helper that does not close the EntityManager on failure
     * (unlike {@see EntityManagerInterface::wrapInTransaction}), so accept-failure
     * audits can still be written after rollback.
     *
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

    private function assertStudentActor(User $user): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($user)) {
            throw ParentStudentLinkException::unauthorized();
        }
        if (!\in_array(UserRole::Student->value, $user->getRoles(), true)) {
            throw ParentStudentLinkException::unauthorized();
        }
    }

    private function assertParentRecipient(User $user): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($user)) {
            throw ParentStudentLinkException::unauthorized();
        }
        if (!\in_array(UserRole::Parent->value, $user->getRoles(), true)) {
            throw ParentStudentLinkException::unauthorized();
        }
    }

    private function generatePlainCode(): string
    {
        return bin2hex(random_bytes(self::PLAIN_CODE_BYTES));
    }

    private function consumeIssueLimit(Uuid $studentId, Uuid $parentId): void
    {
        // Outside DB TX on purpose: cache/Redis state must survive rollback.
        $key = 'psl_issue:'.$studentId->toRfc4122().':'.$parentId->toRfc4122();
        if (!$this->issueLimiter->create($key)->consume(1)->isAccepted()) {
            throw ParentStudentLinkException::rateLimited();
        }
    }

    private function consumeAcceptLimit(Uuid $parentId, Uuid $invitationId): void
    {
        // Outside DB TX on purpose: wrong-code attempts still burn the budget after rollback.
        $key = 'psl_accept:'.$parentId->toRfc4122().':'.$invitationId->toRfc4122();
        if (!$this->acceptLimiter->create($key)->consume(1)->isAccepted()) {
            throw ParentStudentLinkException::rateLimited();
        }
    }

    private function auditAcceptFailure(Uuid $actorId, Uuid $invitationId, string $reasonCode): void
    {
        $actor = $this->users->findOneById($actorId);
        if (!$actor instanceof User) {
            return;
        }

        $this->auditRecorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::ParentStudentLinkAcceptFailed,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Failure,
            actorUser: $actor,
            metadata: [
                'source' => 'parent_student_link_consent_manager',
                'invitation_id' => $invitationId->toRfc4122(),
                'reason_code' => $reasonCode,
            ],
            correlationId: $invitationId->toRfc4122().':accept_failed',
            captureRequestHashes: false,
        ));
    }
}

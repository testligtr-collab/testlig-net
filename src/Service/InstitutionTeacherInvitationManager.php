<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\InstitutionTeacherInviteDispatch;
use App\Dto\InstitutionTeacherInvitePreview;
use App\Dto\SecurityAuditContext;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\InstitutionTeacherInvitation;
use App\Entity\InstitutionTeacherInvitePendingGuard;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\InstitutionTeacherInviteException;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionTeacherInvitationRepository;
use App\Repository\InstitutionTeacherInvitePendingGuardRepository;
use App\Repository\UserRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher invites and acceptance. Classroom assignment stays on ClassroomTeacherAssignmentManager.
 *
 * PersonalInvitation is not used: it requires an existing recipient and does not grant membership.
 * Lock order: Institution → Users (UUID ascending) → pending guard → invitation → membership.
 */
final class InstitutionTeacherInvitationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InstitutionTeacherInvitationRepository $invitations,
        private readonly InstitutionTeacherInvitePendingGuardRepository $guards,
        private readonly InstitutionMembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly EmailNormalizer $emailNormalizer,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly InstitutionTeacherInviteSender $sender,
        private readonly RateLimitKeyHasher $rateKeys,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.institution_teacher_invite')]
        private readonly RateLimiterFactory $issueLimiter,
        #[Autowire(service: 'limiter.institution_teacher_invite_resend')]
        private readonly RateLimiterFactory $resendLimiter,
        #[Autowire(service: 'limiter.institution_teacher_invite_accept')]
        private readonly RateLimiterFactory $acceptLimiter,
    ) {
    }

    public function issue(User $actor, Institution $institution, string $email, string $note): InstitutionTeacherInviteDispatch
    {
        if (!$this->issueLimiter->create($actor->getId()->toRfc4122())->consume(1)->isAccepted()) {
            throw InstitutionTeacherInviteException::rateLimited();
        }
        $normalized = $this->normalizeEmail($email);
        $operatorNote = $this->note($note);
        $kept = false;
        try {
            $created = $this->entityManager->wrapInTransaction(function () use ($actor, $institution, $normalized, $operatorNote, &$kept): ?InstitutionTeacherInviteDispatch {
                $lockedInstitution = $this->lockInstitution($institution->getId());
                $freshActor = $this->lockActor($actor->getId());
                $this->assertLeader($freshActor, $lockedInstitution);
                $this->assertNotAlreadyMember($lockedInstitution, $normalized);
                $guard = $this->guards->findOneForUpdate($lockedInstitution, $normalized);
                $now = $this->now();
                if ($guard instanceof InstitutionTeacherInvitePendingGuard) {
                    $existing = $guard->getInvitation();
                    if ($existing->isUsable($now)) {
                        $kept = true;

                        return null;
                    }
                    if (!$existing->isConsumed() && !$existing->isRevoked()) {
                        $existing->revoke($now);
                    }
                    $this->entityManager->remove($guard);
                    $this->entityManager->flush();
                }

                return $this->insertInvite($lockedInstitution, $freshActor, $normalized, $operatorNote, $now, SecurityAuditAction::InstitutionTeacherInvited, 'panel_teacher_invite');
            });
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw InstitutionTeacherInviteException::conflict();
        }
        if ($created instanceof InstitutionTeacherInviteDispatch) {
            return $created;
        }
        if (!$kept) {
            throw InstitutionTeacherInviteException::conflict();
        }
        if (!$this->resendLimiter->create($this->resendKey($institution->getId(), $normalized))->consume(1)->isAccepted()) {
            return InstitutionTeacherInviteDispatch::silent();
        }

        return $this->rotateEmail($actor, $institution, $normalized, SecurityAuditAction::InstitutionTeacherInviteResent, 'panel_teacher_invite');
    }

    public function resend(User $actor, Institution $institution, string $reference): InstitutionTeacherInviteDispatch
    {
        $invitation = $this->invitationInInstitution($institution, $reference);
        if (!$invitation instanceof InstitutionTeacherInvitation) {
            throw InstitutionTeacherInviteException::notFound();
        }
        $normalized = $invitation->getNormalizedEmail();
        if (!$this->resendLimiter->create($this->resendKey($institution->getId(), $normalized))->consume(1)->isAccepted()) {
            throw InstitutionTeacherInviteException::rateLimited();
        }

        return $this->rotateEmail($actor, $institution, $normalized, SecurityAuditAction::InstitutionTeacherInviteResent, 'panel_teacher_resend');
    }

    public function revoke(User $actor, Institution $institution, string $reference): void
    {
        $target = $this->invitationInInstitution($institution, $reference);
        if (!$target instanceof InstitutionTeacherInvitation) {
            throw InstitutionTeacherInviteException::notFound();
        }
        $invitationId = $target->getId();
        try {
            $this->entityManager->wrapInTransaction(function () use ($actor, $institution, $invitationId): void {
                $lockedInstitution = $this->lockInstitution($institution->getId());
                $freshActor = $this->lockActor($actor->getId());
                $this->assertLeader($freshActor, $lockedInstitution);
                $invitation = $this->invitations->findOneByIdForUpdate($invitationId);
                if (!$invitation instanceof InstitutionTeacherInvitation
                    || !$invitation->getInstitution()->getId()->equals($lockedInstitution->getId())) {
                    throw InstitutionTeacherInviteException::notFound();
                }
                if ($invitation->isConsumed() || $invitation->isRevoked()) {
                    throw InstitutionTeacherInviteException::unavailable();
                }
                $now = $this->now();
                $invitation->revoke($now);
                $this->removeGuard($lockedInstitution, $invitation->getNormalizedEmail());
                $this->audit($freshActor, null, SecurityAuditAction::InstitutionTeacherInviteRevoked, [
                    'source' => 'institution_teacher_invitation',
                    'reason_code' => 'panel_teacher_revoke',
                    'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                    'invitation_id' => $invitation->getId()->toRfc4122(),
                    'new_status' => 'revoked',
                ]);
                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw InstitutionTeacherInviteException::conflict();
        }
    }

    public function preview(string $plainToken): ?InstitutionTeacherInvitePreview
    {
        $invitation = $this->findByPlain($plainToken);
        if (!$invitation instanceof InstitutionTeacherInvitation || !$invitation->isUsable($this->now())) {
            return null;
        }
        $zone = new \DateTimeZone('Europe/Istanbul');

        return new InstitutionTeacherInvitePreview(
            $invitation->getInstitution()->getName(),
            $invitation->getExpiresAt()->setTimezone($zone)->format('d.m.Y H:i'),
            $this->users->existsWithNormalizedEmail($invitation->getNormalizedEmail()),
        );
    }

    public function accept(User $actor, string $plainToken): void
    {
        if (!$this->acceptLimiter->create($actor->getId()->toRfc4122())->consume(1)->isAccepted()) {
            throw InstitutionTeacherInviteException::rateLimited();
        }
        $this->redeem($actor, $plainToken, true);
    }

    public function decline(User $actor, string $plainToken): void
    {
        $this->redeem($actor, $plainToken, false);
    }

    public function deliver(InstitutionTeacherInviteDispatch $dispatch): void
    {
        if (!$dispatch->send || '' === $dispatch->plainToken) {
            return;
        }
        try {
            $this->sender->send(
                $dispatch->recipientEmail,
                $dispatch->institutionName,
                $dispatch->expiresAt,
                $dispatch->plainToken,
            );
        } catch (TransportExceptionInterface) {
            throw InstitutionTeacherInviteException::mailFailed();
        }
    }

    private function redeem(User $actor, string $plainToken, bool $accept): void
    {
        $digest = $this->digestOrNull($plainToken);
        if (null === $digest) {
            throw InstitutionTeacherInviteException::notFound();
        }
        $preview = $this->invitations->findOneByDigest($digest);
        if (!$preview instanceof InstitutionTeacherInvitation) {
            $this->dummyCompare($digest);
            throw InstitutionTeacherInviteException::notFound();
        }
        $institutionId = $preview->getInstitution()->getId();
        $actorId = $actor->getId();
        $membershipId = null;
        try {
            $membershipId = $this->entityManager->wrapInTransaction(function () use ($digest, $institutionId, $actorId, $accept): ?Uuid {
                $lockedInstitution = $this->lockInstitution($institutionId);
                $freshActor = $this->lockActor($actorId);
                $invitation = $this->invitations->findOneByDigestForUpdate($digest);
                if (!$invitation instanceof InstitutionTeacherInvitation
                    || !$invitation->getInstitution()->getId()->equals($lockedInstitution->getId())
                    || !$invitation->isUsable($this->now())) {
                    throw InstitutionTeacherInviteException::unavailable();
                }
                if (!hash_equals($invitation->getNormalizedEmail(), $freshActor->getNormalizedEmail())) {
                    throw InstitutionTeacherInviteException::accountMismatch();
                }
                if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($freshActor)) {
                    throw InstitutionTeacherInviteException::accountNotReady();
                }
                if (InstitutionStatus::Active !== $lockedInstitution->getStatus()) {
                    throw InstitutionTeacherInviteException::unavailable();
                }
                $now = $this->now();
                if (!$accept) {
                    $invitation->revoke($now);
                    $this->removeGuard($lockedInstitution, $invitation->getNormalizedEmail());
                    $this->audit($freshActor, $freshActor, SecurityAuditAction::InstitutionTeacherInviteRevoked, [
                        'source' => 'institution_teacher_invitation',
                        'reason_code' => 'teacher_invite_decline',
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'invitation_id' => $invitation->getId()->toRfc4122(),
                        'new_status' => 'revoked',
                    ]);
                    $this->entityManager->flush();

                    return null;
                }
                if (null !== $this->freshEntities->findFreshMembershipForUser($freshActor->getId(), $lockedInstitution->getId(), LockMode::PESSIMISTIC_WRITE)) {
                    throw InstitutionTeacherInviteException::notEligible();
                }
                $membership = InstitutionMembership::createActive(
                    $lockedInstitution,
                    $freshActor,
                    InstitutionMembershipRole::Teacher,
                    $now,
                );
                $this->memberships->save($membership, false);
                $invitation->consume($now);
                $this->removeGuard($lockedInstitution, $invitation->getNormalizedEmail());
                $this->audit($freshActor, $freshActor, SecurityAuditAction::InstitutionMemberAdded, [
                    'source' => 'institution_teacher_invitation',
                    'reason_code' => 'teacher_invite_accept',
                    'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                    'membership_role' => InstitutionMembershipRole::Teacher->value,
                    'new_status' => $membership->getStatus()->value,
                ]);
                $this->audit($freshActor, $freshActor, SecurityAuditAction::InstitutionTeacherInviteAccepted, [
                    'source' => 'institution_teacher_invitation',
                    'reason_code' => 'teacher_invite_accept',
                    'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                    'invitation_id' => $invitation->getId()->toRfc4122(),
                    'membership_id' => $membership->getId()->toRfc4122(),
                    'membership_role' => InstitutionMembershipRole::Teacher->value,
                    'new_status' => InstitutionMembershipStatus::Active->value,
                ]);
                $this->entityManager->flush();

                return $membership->getId();
            });
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw InstitutionTeacherInviteException::conflict();
        }
        if ($membershipId instanceof Uuid) {
            $this->authCache->invalidateMembership($actorId, $institutionId);
        }
    }

    private function rotateEmail(
        User $actor,
        Institution $institution,
        string $normalized,
        SecurityAuditAction $action,
        string $reasonCode,
    ): InstitutionTeacherInviteDispatch {
        try {
            return $this->entityManager->wrapInTransaction(function () use ($actor, $institution, $normalized, $action, $reasonCode): InstitutionTeacherInviteDispatch {
                $lockedInstitution = $this->lockInstitution($institution->getId());
                $freshActor = $this->lockActor($actor->getId());
                $this->assertLeader($freshActor, $lockedInstitution);
                $guard = $this->guards->findOneForUpdate($lockedInstitution, $normalized);
                if (!$guard instanceof InstitutionTeacherInvitePendingGuard) {
                    throw InstitutionTeacherInviteException::unavailable();
                }
                $invitation = $guard->getInvitation();
                $now = $this->now();
                if (!$invitation->isUsable($now)) {
                    throw InstitutionTeacherInviteException::unavailable();
                }
                $plain = $this->newToken();
                $expiresAt = $now->add(new \DateInterval(InstitutionTeacherInvitation::TTL));
                $invitation->replaceToken(
                    $this->hasher->hashInstitutionTeacherInvite($plain),
                    $this->hasher->getKeyId(),
                    $expiresAt,
                    $now,
                );
                $this->audit($freshActor, null, $action, [
                    'source' => 'institution_teacher_invitation',
                    'reason_code' => $reasonCode,
                    'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                    'invitation_id' => $invitation->getId()->toRfc4122(),
                    'new_status' => 'pending',
                ]);
                $this->entityManager->flush();

                return new InstitutionTeacherInviteDispatch(true, $normalized, $lockedInstitution->getName(), $expiresAt, $plain);
            });
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw InstitutionTeacherInviteException::conflict();
        }
    }

    private function insertInvite(
        Institution $institution,
        User $actor,
        string $normalized,
        ?string $operatorNote,
        \DateTimeImmutable $now,
        SecurityAuditAction $action,
        string $reasonCode,
    ): InstitutionTeacherInviteDispatch {
        $plain = $this->newToken();
        $expiresAt = $now->add(new \DateInterval(InstitutionTeacherInvitation::TTL));
        $invitation = InstitutionTeacherInvitation::issue(
            $institution,
            $actor,
            $normalized,
            $this->hasher->hashInstitutionTeacherInvite($plain),
            $this->hasher->getKeyId(),
            $operatorNote,
            $expiresAt,
            $now,
        );
        $this->invitations->save($invitation, false);
        $this->guards->save(new InstitutionTeacherInvitePendingGuard($institution, $normalized, $invitation), false);
        $this->audit($actor, null, $action, [
            'source' => 'institution_teacher_invitation',
            'reason_code' => $reasonCode,
            'institution_id' => $institution->getId()->toRfc4122(),
            'invitation_id' => $invitation->getId()->toRfc4122(),
            'new_status' => 'pending',
            'membership_role' => InstitutionMembershipRole::Teacher->value,
        ]);
        $this->entityManager->flush();

        return new InstitutionTeacherInviteDispatch(true, $normalized, $institution->getName(), $expiresAt, $plain);
    }

    private function assertNotAlreadyMember(Institution $institution, string $normalized): void
    {
        $user = $this->users->findOneByNormalizedEmail($normalized);
        if (!$user instanceof User) {
            return;
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($user->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership) {
            return;
        }
        if (InstitutionMembershipStatus::Active === $membership->getStatus()
            && InstitutionMembershipRole::Teacher === $membership->getRole()) {
            throw InstitutionTeacherInviteException::alreadyTeacher();
        }
        throw InstitutionTeacherInviteException::notEligible();
    }

    private function assertLeader(User $actor, Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw InstitutionTeacherInviteException::unauthorized();
        }
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            throw InstitutionTeacherInviteException::unavailable();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()) {
            throw InstitutionTeacherInviteException::unauthorized();
        }
        $role = $membership->getRole();
        if (InstitutionMembershipRole::Owner !== $role && InstitutionMembershipRole::Manager !== $role) {
            throw InstitutionTeacherInviteException::unauthorized();
        }
    }

    private function lockInstitution(Uuid $institutionId): Institution
    {
        $locked = $this->freshEntities->findFreshLockedInstitution($institutionId, LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof Institution) {
            throw InstitutionTeacherInviteException::notFound();
        }

        return $locked;
    }

    private function lockActor(Uuid $actorId): User
    {
        $users = $this->freshEntities->findFreshLockedUsers([$actorId]);
        $actor = $users[$actorId->toRfc4122()] ?? null;
        if (!$actor instanceof User) {
            throw InstitutionTeacherInviteException::notFound();
        }

        return $actor;
    }

    private function invitationInInstitution(Institution $institution, string $reference): ?InstitutionTeacherInvitation
    {
        $reference = strtolower(trim($reference));
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return null;
        }
        /** @var list<array{id: mixed}> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('i.id AS id')
            ->from(InstitutionTeacherInvitation::class, 'i')
            ->andWhere('i.institution = :institution')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();
        foreach ($ids as $row) {
            $id = $row['id'] instanceof Uuid ? $row['id'] : (\is_string($row['id']) && Uuid::isValid($row['id']) ? Uuid::fromString($row['id']) : null);
            if (!$id instanceof Uuid || !hash_equals($this->hasher->workspaceReference('teacher_invite', $id), $reference)) {
                continue;
            }
            $invitation = $this->entityManager->find(InstitutionTeacherInvitation::class, $id);

            return $invitation instanceof InstitutionTeacherInvitation
                && $invitation->getInstitution()->getId()->equals($institution->getId())
                ? $invitation
                : null;
        }

        return null;
    }

    private function findByPlain(string $plainToken): ?InstitutionTeacherInvitation
    {
        $digest = $this->digestOrNull($plainToken);
        if (null === $digest) {
            return null;
        }

        return $this->invitations->findOneByDigest($digest);
    }

    private function digestOrNull(string $plainToken): ?string
    {
        try {
            return $this->hasher->hashInstitutionTeacherInvite($plainToken);
        } catch (\Throwable) {
            return null;
        }
    }

    private function dummyCompare(string $digest): void
    {
        if (hash_equals(str_repeat('0', 64), $digest)) {
            throw InstitutionTeacherInviteException::notFound();
        }
    }

    private function removeGuard(Institution $institution, string $normalizedEmail): void
    {
        $guard = $this->guards->findOneForUpdate($institution, $normalizedEmail);
        if ($guard instanceof InstitutionTeacherInvitePendingGuard) {
            $this->entityManager->remove($guard);
        }
    }

    private function normalizeEmail(string $email): string
    {
        try {
            return $this->emailNormalizer->normalize($email);
        } catch (\InvalidArgumentException) {
            throw InstitutionTeacherInviteException::invalidInput();
        }
    }

    private function note(string $note): ?string
    {
        $note = trim($note);
        if ('' === $note) {
            return null;
        }
        if (mb_strlen($note) > InstitutionTeacherInvitation::NOTE_MAX) {
            throw InstitutionTeacherInviteException::invalidInput();
        }

        return $note;
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private function resendKey(Uuid $institutionId, string $normalizedEmail): string
    {
        return $institutionId->toRfc4122().':'.$this->rateKeys->hashEmail($normalizedEmail);
    }

    /**
     * @param array<string, bool|float|int|string|list<bool|float|int|string>|null> $metadata
     */
    private function audit(?User $actor, ?User $subject, SecurityAuditAction $action, array $metadata): void
    {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            subjectUser: $subject,
            metadata: $metadata,
            captureRequestHashes: false,
        ), false);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\InstitutionStudentInviteDispatch;
use App\Dto\InstitutionStudentInvitePreview;
use App\Dto\SecurityAuditContext;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\InstitutionStudentInvitation;
use App\Entity\InstitutionStudentInvitePendingGuard;
use App\Entity\StudentProfile;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Exception\ClassroomStudentEnrollmentException;
use App\Exception\InstitutionStudentInviteException;
use App\Repository\ClassroomRepository;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionStudentInvitationRepository;
use App\Repository\InstitutionStudentInvitePendingGuardRepository;
use App\Repository\StudentProfileRepository;
use App\Repository\UserRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Student invites for one classroom. Acceptance creates a student membership and a classroom enrollment together.
 *
 * Teacher invite rows are not reused. The HMAC context string is institution_student_invite_v1.
 * A pending guard keeps one live invite per institution, academic year, and email.
 * Parental consent is not recorded and is not implied by accepting the invite.
 *
 * Lock order: Institution → AcademicYear → Classroom → Users → pending guard → invitation → membership.
 */
final class InstitutionStudentInvitationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InstitutionStudentInvitationRepository $invitations,
        private readonly InstitutionStudentInvitePendingGuardRepository $guards,
        private readonly InstitutionMembershipRepository $memberships,
        private readonly StudentProfileRepository $profiles,
        private readonly ClassroomRepository $classrooms,
        private readonly UserRepository $users,
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly EmailNormalizer $emailNormalizer,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly ClassroomStudentEnrollmentManager $enrollments,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly InstitutionStudentInviteSender $sender,
        private readonly RateLimitKeyHasher $rateKeys,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.institution_student_invite')]
        private readonly RateLimiterFactory $issueLimiter,
        #[Autowire(service: 'limiter.institution_student_invite_resend')]
        private readonly RateLimiterFactory $resendLimiter,
        #[Autowire(service: 'limiter.institution_student_invite_accept')]
        private readonly RateLimiterFactory $acceptLimiter,
    ) {
    }

    public function issue(User $actor, Institution $institution, string $classroomReference, string $email, string $note): InstitutionStudentInviteDispatch
    {
        if (!$this->issueLimiter->create($actor->getId()->toRfc4122())->consume(1)->isAccepted()) {
            throw InstitutionStudentInviteException::rateLimited();
        }
        $classroom = $this->classroomInInstitution($institution, $classroomReference);
        if (!$classroom instanceof Classroom) {
            throw InstitutionStudentInviteException::notFound();
        }
        $normalized = $this->normalizeEmail($email);
        $operatorNote = $this->note($note);
        $kept = false;
        try {
            $created = $this->entityManager->wrapInTransaction(function () use ($actor, $institution, $classroom, $normalized, $operatorNote, &$kept): ?InstitutionStudentInviteDispatch {
                $locked = $this->lockClassroomScope($institution->getId(), $classroom->getAcademicYear()->getId(), $classroom->getId());
                $freshActor = $this->lockActor($actor->getId());
                $this->assertLeader($freshActor, $locked['institution']);
                $this->assertInvitable($locked['institution'], $locked['year'], $locked['classroom'], $normalized);
                $guard = $this->guards->findOneForUpdate($locked['institution'], $locked['year'], $normalized);
                $now = $this->now();
                if ($guard instanceof InstitutionStudentInvitePendingGuard) {
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

                return $this->insertInvite($locked['institution'], $locked['year'], $locked['classroom'], $freshActor, $normalized, $operatorNote, $now, SecurityAuditAction::InstitutionStudentInvited, 'panel_student_invite');
            });
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw InstitutionStudentInviteException::conflict();
        }
        if ($created instanceof InstitutionStudentInviteDispatch) {
            return $created;
        }
        if (!$kept) {
            throw InstitutionStudentInviteException::conflict();
        }
        if (!$this->resendLimiter->create($this->resendKey($institution->getId(), $classroom->getAcademicYear()->getId(), $normalized))->consume(1)->isAccepted()) {
            return InstitutionStudentInviteDispatch::silent();
        }

        return $this->rotateEmail($actor, $institution, $classroom, $normalized, SecurityAuditAction::InstitutionStudentInviteResent, 'panel_student_invite');
    }

    public function resend(User $actor, Institution $institution, string $classroomReference, string $reference): InstitutionStudentInviteDispatch
    {
        $classroom = $this->classroomInInstitution($institution, $classroomReference);
        if (!$classroom instanceof Classroom) {
            throw InstitutionStudentInviteException::notFound();
        }
        $invitation = $this->invitationInClassroom($classroom, $reference);
        if (!$invitation instanceof InstitutionStudentInvitation) {
            throw InstitutionStudentInviteException::notFound();
        }
        $normalized = $invitation->getNormalizedEmail();
        if (!$this->resendLimiter->create($this->resendKey($institution->getId(), $invitation->getAcademicYear()->getId(), $normalized))->consume(1)->isAccepted()) {
            throw InstitutionStudentInviteException::rateLimited();
        }

        return $this->rotateEmail($actor, $institution, $classroom, $normalized, SecurityAuditAction::InstitutionStudentInviteResent, 'panel_student_resend');
    }

    public function revoke(User $actor, Institution $institution, string $classroomReference, string $reference): void
    {
        $classroom = $this->classroomInInstitution($institution, $classroomReference);
        if (!$classroom instanceof Classroom) {
            throw InstitutionStudentInviteException::notFound();
        }
        $target = $this->invitationInClassroom($classroom, $reference);
        if (!$target instanceof InstitutionStudentInvitation) {
            throw InstitutionStudentInviteException::notFound();
        }
        $invitationId = $target->getId();
        try {
            $this->entityManager->wrapInTransaction(function () use ($actor, $institution, $classroom, $invitationId): void {
                $locked = $this->lockClassroomScope($institution->getId(), $classroom->getAcademicYear()->getId(), $classroom->getId());
                $freshActor = $this->lockActor($actor->getId());
                $this->assertLeader($freshActor, $locked['institution']);
                $invitation = $this->invitations->findOneByIdForUpdate($invitationId);
                if (!$invitation instanceof InstitutionStudentInvitation
                    || !$invitation->getClassroom()->getId()->equals($locked['classroom']->getId())) {
                    throw InstitutionStudentInviteException::notFound();
                }
                if ($invitation->isConsumed() || $invitation->isRevoked()) {
                    throw InstitutionStudentInviteException::unavailable();
                }
                $now = $this->now();
                $invitation->revoke($now);
                $this->removeGuard($locked['institution'], $locked['year'], $invitation->getNormalizedEmail());
                $this->audit($freshActor, null, SecurityAuditAction::InstitutionStudentInviteRevoked, [
                    'source' => 'institution_student_invitation',
                    'reason_code' => 'panel_student_revoke',
                    'institution_id' => $locked['institution']->getId()->toRfc4122(),
                    'academic_year_id' => $locked['year']->getId()->toRfc4122(),
                    'classroom_id' => $locked['classroom']->getId()->toRfc4122(),
                    'invitation_id' => $invitation->getId()->toRfc4122(),
                    'new_status' => 'revoked',
                ]);
                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw InstitutionStudentInviteException::conflict();
        }
    }

    public function preview(string $plainToken): ?InstitutionStudentInvitePreview
    {
        $invitation = $this->findByPlain($plainToken);
        if (!$invitation instanceof InstitutionStudentInvitation || !$invitation->isUsable($this->now())) {
            return null;
        }
        $zone = new \DateTimeZone('Europe/Istanbul');

        return new InstitutionStudentInvitePreview(
            $invitation->getInstitution()->getName(),
            $invitation->getClassroom()->getName(),
            $invitation->getExpiresAt()->setTimezone($zone)->format('d.m.Y H:i'),
        );
    }

    public function accept(User $actor, string $plainToken): void
    {
        if (!$this->acceptLimiter->create($actor->getId()->toRfc4122())->consume(1)->isAccepted()) {
            throw InstitutionStudentInviteException::rateLimited();
        }
        $this->redeem($actor, $plainToken, true);
    }

    public function decline(User $actor, string $plainToken): void
    {
        $this->redeem($actor, $plainToken, false);
    }

    public function deliver(InstitutionStudentInviteDispatch $dispatch): void
    {
        if (!$dispatch->send || '' === $dispatch->plainToken) {
            return;
        }
        try {
            $this->sender->send(
                $dispatch->recipientEmail,
                $dispatch->institutionName,
                $dispatch->classroomName,
                $dispatch->expiresAt,
                $dispatch->plainToken,
            );
        } catch (TransportExceptionInterface) {
            throw InstitutionStudentInviteException::mailFailed();
        }
    }

    private function redeem(User $actor, string $plainToken, bool $accept): void
    {
        $digest = $this->digestOrNull($plainToken);
        if (null === $digest) {
            throw InstitutionStudentInviteException::notFound();
        }
        $preview = $this->invitations->findOneByDigest($digest);
        if (!$preview instanceof InstitutionStudentInvitation) {
            $this->dummyCompare($digest);
            throw InstitutionStudentInviteException::notFound();
        }
        $institutionId = $preview->getInstitution()->getId();
        $yearId = $preview->getAcademicYear()->getId();
        $classroomId = $preview->getClassroom()->getId();
        $actorId = $actor->getId();
        $enrolled = false;
        try {
            $enrolled = $this->entityManager->wrapInTransaction(function () use ($digest, $institutionId, $yearId, $classroomId, $actorId, $accept): bool {
                $locked = $this->lockClassroomScope($institutionId, $yearId, $classroomId);
                $freshActor = $this->lockActor($actorId);
                $invitation = $this->invitations->findOneByDigestForUpdate($digest);
                if (!$invitation instanceof InstitutionStudentInvitation
                    || !$invitation->getClassroom()->getId()->equals($locked['classroom']->getId())
                    || !$invitation->isUsable($this->now())) {
                    throw InstitutionStudentInviteException::unavailable();
                }
                if (!hash_equals($invitation->getNormalizedEmail(), $freshActor->getNormalizedEmail())) {
                    throw InstitutionStudentInviteException::accountMismatch();
                }
                if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($freshActor)) {
                    throw InstitutionStudentInviteException::accountNotReady();
                }
                $now = $this->now();
                if (!$accept) {
                    $invitation->revoke($now);
                    $this->removeGuard($locked['institution'], $locked['year'], $invitation->getNormalizedEmail());
                    $this->audit($freshActor, $freshActor, SecurityAuditAction::InstitutionStudentInviteRevoked, [
                        'source' => 'institution_student_invitation',
                        'reason_code' => 'student_invite_decline',
                        'institution_id' => $locked['institution']->getId()->toRfc4122(),
                        'invitation_id' => $invitation->getId()->toRfc4122(),
                        'new_status' => 'revoked',
                    ]);
                    $this->entityManager->flush();

                    return false;
                }
                $this->assertOpen($locked['institution'], $locked['year'], $locked['classroom']);
                if (!\in_array(UserRole::Student->value, $freshActor->getRoles(), true)) {
                    throw InstitutionStudentInviteException::notEligible();
                }
                $profile = $this->profiles->findOneByUserId($freshActor->getId());
                if (!$profile instanceof StudentProfile || !$profile->isOnboardingCompleted()) {
                    throw InstitutionStudentInviteException::profileNotReady();
                }
                if ($profile->getGradeLevel() !== $locked['classroom']->getGradeLevel()) {
                    throw InstitutionStudentInviteException::gradeMismatch();
                }
                $membership = $this->studentMembership($locked['institution'], $freshActor, $now);
                if ($this->yearGuardExists($locked['year']->getId(), $membership->getId())) {
                    throw InstitutionStudentInviteException::notEligible();
                }
                try {
                    $enrollment = $this->enrollments->enrollAcceptedStudent($locked['classroom'], $membership);
                } catch (ClassroomStudentEnrollmentException $exception) {
                    throw $this->mapEnrollment($exception);
                }
                $invitation->consume($now);
                $this->removeGuard($locked['institution'], $locked['year'], $invitation->getNormalizedEmail());
                $this->audit($freshActor, $freshActor, SecurityAuditAction::InstitutionStudentInviteAccepted, [
                    'source' => 'institution_student_invitation',
                    'reason_code' => 'student_invite_accept',
                    'institution_id' => $locked['institution']->getId()->toRfc4122(),
                    'academic_year_id' => $locked['year']->getId()->toRfc4122(),
                    'classroom_id' => $locked['classroom']->getId()->toRfc4122(),
                    'invitation_id' => $invitation->getId()->toRfc4122(),
                    'membership_id' => $membership->getId()->toRfc4122(),
                    'enrollment_id' => $enrollment->getId()->toRfc4122(),
                    'membership_role' => InstitutionMembershipRole::Student->value,
                    'new_status' => InstitutionMembershipStatus::Active->value,
                ]);
                $this->entityManager->flush();

                return true;
            });
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw InstitutionStudentInviteException::conflict();
        }
        if ($enrolled) {
            $this->authCache->invalidateMembership($actorId, $institutionId);
            $this->authCache->invalidateClassroom($classroomId);
            $this->authCache->invalidateStudentEnrollment($actorId, $classroomId);
        }
    }

    private function rotateEmail(
        User $actor,
        Institution $institution,
        Classroom $classroom,
        string $normalized,
        SecurityAuditAction $action,
        string $reasonCode,
    ): InstitutionStudentInviteDispatch {
        try {
            return $this->entityManager->wrapInTransaction(function () use ($actor, $institution, $classroom, $normalized, $action, $reasonCode): InstitutionStudentInviteDispatch {
                $locked = $this->lockClassroomScope($institution->getId(), $classroom->getAcademicYear()->getId(), $classroom->getId());
                $freshActor = $this->lockActor($actor->getId());
                $this->assertLeader($freshActor, $locked['institution']);
                $this->assertOpen($locked['institution'], $locked['year'], $locked['classroom']);
                $this->assertCapacity($locked['classroom']);
                $guard = $this->guards->findOneForUpdate($locked['institution'], $locked['year'], $normalized);
                if (!$guard instanceof InstitutionStudentInvitePendingGuard) {
                    throw InstitutionStudentInviteException::unavailable();
                }
                $invitation = $guard->getInvitation();
                $now = $this->now();
                if (!$invitation->isUsable($now)) {
                    throw InstitutionStudentInviteException::unavailable();
                }
                $plain = InstitutionInviteToken::generate();
                $expiresAt = $now->add(new \DateInterval(InstitutionStudentInvitation::TTL));
                $invitation->replaceToken(
                    $locked['classroom'],
                    $this->hasher->hashInstitutionStudentInvite($plain),
                    $this->hasher->getKeyId(),
                    $expiresAt,
                    $now,
                );
                $this->audit($freshActor, null, $action, [
                    'source' => 'institution_student_invitation',
                    'reason_code' => $reasonCode,
                    'institution_id' => $locked['institution']->getId()->toRfc4122(),
                    'classroom_id' => $locked['classroom']->getId()->toRfc4122(),
                    'invitation_id' => $invitation->getId()->toRfc4122(),
                    'new_status' => 'pending',
                ]);
                $this->entityManager->flush();

                return new InstitutionStudentInviteDispatch(true, $normalized, $locked['institution']->getName(), $locked['classroom']->getName(), $expiresAt, $plain);
            });
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw InstitutionStudentInviteException::conflict();
        }
    }

    private function insertInvite(
        Institution $institution,
        AcademicYear $year,
        Classroom $classroom,
        User $actor,
        string $normalized,
        ?string $operatorNote,
        \DateTimeImmutable $now,
        SecurityAuditAction $action,
        string $reasonCode,
    ): InstitutionStudentInviteDispatch {
        $plain = InstitutionInviteToken::generate();
        $expiresAt = $now->add(new \DateInterval(InstitutionStudentInvitation::TTL));
        $invitation = InstitutionStudentInvitation::issue(
            $institution,
            $year,
            $classroom,
            $actor,
            $normalized,
            $this->hasher->hashInstitutionStudentInvite($plain),
            $this->hasher->getKeyId(),
            $operatorNote,
            $expiresAt,
            $now,
        );
        $this->invitations->save($invitation, false);
        $this->guards->save(new InstitutionStudentInvitePendingGuard($institution, $year, $normalized, $invitation), false);
        $this->audit($actor, null, $action, [
            'source' => 'institution_student_invitation',
            'reason_code' => $reasonCode,
            'institution_id' => $institution->getId()->toRfc4122(),
            'academic_year_id' => $year->getId()->toRfc4122(),
            'classroom_id' => $classroom->getId()->toRfc4122(),
            'invitation_id' => $invitation->getId()->toRfc4122(),
            'new_status' => 'pending',
            'membership_role' => InstitutionMembershipRole::Student->value,
        ]);
        $this->entityManager->flush();

        return new InstitutionStudentInviteDispatch(true, $normalized, $institution->getName(), $classroom->getName(), $expiresAt, $plain);
    }

    private function studentMembership(Institution $institution, User $user, \DateTimeImmutable $now): InstitutionMembership
    {
        $existing = $this->freshEntities->findFreshMembershipForUser($user->getId(), $institution->getId(), LockMode::PESSIMISTIC_WRITE);
        if ($existing instanceof InstitutionMembership) {
            if (InstitutionMembershipStatus::Active !== $existing->getStatus()
                || InstitutionMembershipRole::Student !== $existing->getRole()) {
                throw InstitutionStudentInviteException::notEligible();
            }

            return $existing;
        }
        $membership = InstitutionMembership::createActive($institution, $user, InstitutionMembershipRole::Student, $now);
        $this->memberships->save($membership, false);
        $this->entityManager->flush();
        $this->audit($user, $user, SecurityAuditAction::InstitutionMemberAdded, [
            'source' => 'institution_student_invitation',
            'reason_code' => 'student_invite_accept',
            'institution_id' => $institution->getId()->toRfc4122(),
            'membership_id' => $membership->getId()->toRfc4122(),
            'membership_role' => InstitutionMembershipRole::Student->value,
            'new_status' => $membership->getStatus()->value,
        ]);

        return $membership;
    }

    private function assertInvitable(Institution $institution, AcademicYear $year, Classroom $classroom, string $normalized): void
    {
        $this->assertOpen($institution, $year, $classroom);
        $this->assertCapacity($classroom);
        $user = $this->users->findOneByNormalizedEmail($normalized);
        if (!$user instanceof User) {
            return;
        }
        $profile = $this->profiles->findOneByUser($user);
        if ($profile instanceof StudentProfile && $profile->isOnboardingCompleted() && $profile->getGradeLevel() !== $classroom->getGradeLevel()) {
            throw InstitutionStudentInviteException::gradeMismatch();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($user->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership) {
            return;
        }
        if (InstitutionMembershipStatus::Active !== $membership->getStatus()
            || InstitutionMembershipRole::Student !== $membership->getRole()
            || $this->yearGuardExists($year->getId(), $membership->getId())) {
            throw InstitutionStudentInviteException::notEligible();
        }
    }

    private function assertOpen(Institution $institution, AcademicYear $year, Classroom $classroom): void
    {
        if (!$classroom->getInstitution()->getId()->equals($institution->getId())
            || !$classroom->getAcademicYear()->getId()->equals($year->getId())) {
            throw InstitutionStudentInviteException::notFound();
        }
        if (InstitutionStatus::Active !== $institution->getStatus()
            || AcademicYearStatus::Active !== $year->getStatus()
            || ClassroomStatus::Active !== $classroom->getStatus()) {
            throw InstitutionStudentInviteException::unavailable();
        }
    }

    private function assertCapacity(Classroom $classroom): void
    {
        $capacity = $classroom->getCapacity();
        if (null !== $capacity && $this->classrooms->countActiveEnrollments($classroom) >= $capacity) {
            throw InstitutionStudentInviteException::capacity();
        }
    }

    private function assertLeader(User $actor, Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw InstitutionStudentInviteException::unauthorized();
        }
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            throw InstitutionStudentInviteException::unavailable();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()) {
            throw InstitutionStudentInviteException::unauthorized();
        }
        $role = $membership->getRole();
        if (InstitutionMembershipRole::Owner !== $role && InstitutionMembershipRole::Manager !== $role) {
            throw InstitutionStudentInviteException::unauthorized();
        }
    }

    /**
     * @return array{institution: Institution, year: AcademicYear, classroom: Classroom}
     */
    private function lockClassroomScope(Uuid $institutionId, Uuid $yearId, Uuid $classroomId): array
    {
        $institution = $this->freshEntities->findFreshLockedInstitution($institutionId, LockMode::PESSIMISTIC_WRITE);
        $year = $this->freshEntities->findFreshLockedAcademicYear($yearId, LockMode::PESSIMISTIC_WRITE);
        $classroom = $this->freshEntities->findFreshLockedClassroom($classroomId, LockMode::PESSIMISTIC_WRITE);
        if (!$institution instanceof Institution || !$year instanceof AcademicYear || !$classroom instanceof Classroom) {
            throw InstitutionStudentInviteException::notFound();
        }

        return ['institution' => $institution, 'year' => $year, 'classroom' => $classroom];
    }

    private function lockActor(Uuid $actorId): User
    {
        $users = $this->freshEntities->findFreshLockedUsers([$actorId]);
        $actor = $users[$actorId->toRfc4122()] ?? null;
        if (!$actor instanceof User) {
            throw InstitutionStudentInviteException::notFound();
        }

        return $actor;
    }

    private function classroomInInstitution(Institution $institution, string $reference): ?Classroom
    {
        $reference = strtolower(trim($reference));
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return null;
        }
        /** @var list<array{id: mixed}> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('c.id AS id')
            ->from(Classroom::class, 'c')
            ->andWhere('c.institution = :institution')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();
        foreach ($ids as $row) {
            $id = $row['id'] instanceof Uuid ? $row['id'] : (\is_string($row['id']) && Uuid::isValid($row['id']) ? Uuid::fromString($row['id']) : null);
            if (!$id instanceof Uuid || !hash_equals($this->hasher->workspaceReference('classroom', $id), $reference)) {
                continue;
            }
            $classroom = $this->entityManager->find(Classroom::class, $id);

            return $classroom instanceof Classroom && $classroom->getInstitution()->getId()->equals($institution->getId())
                ? $classroom
                : null;
        }

        return null;
    }

    private function invitationInClassroom(Classroom $classroom, string $reference): ?InstitutionStudentInvitation
    {
        $reference = strtolower(trim($reference));
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return null;
        }
        /** @var list<array{id: mixed}> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('i.id AS id')
            ->from(InstitutionStudentInvitation::class, 'i')
            ->andWhere('i.classroom = :classroom')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();
        foreach ($ids as $row) {
            $id = $row['id'] instanceof Uuid ? $row['id'] : (\is_string($row['id']) && Uuid::isValid($row['id']) ? Uuid::fromString($row['id']) : null);
            if (!$id instanceof Uuid || !hash_equals($this->hasher->workspaceReference('student_invite', $id), $reference)) {
                continue;
            }
            $invitation = $this->entityManager->find(InstitutionStudentInvitation::class, $id);

            return $invitation instanceof InstitutionStudentInvitation
                && $invitation->getClassroom()->getId()->equals($classroom->getId())
                ? $invitation
                : null;
        }

        return null;
    }

    private function findByPlain(string $plainToken): ?InstitutionStudentInvitation
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
            return $this->hasher->hashInstitutionStudentInvite($plainToken);
        } catch (\Throwable) {
            return null;
        }
    }

    private function dummyCompare(string $digest): void
    {
        if (hash_equals(str_repeat('0', 64), $digest)) {
            throw InstitutionStudentInviteException::notFound();
        }
    }

    private function removeGuard(Institution $institution, AcademicYear $year, string $normalizedEmail): void
    {
        $guard = $this->guards->findOneForUpdate($institution, $year, $normalizedEmail);
        if ($guard instanceof InstitutionStudentInvitePendingGuard) {
            $this->entityManager->remove($guard);
        }
    }

    private function yearGuardExists(Uuid $academicYearId, Uuid $studentMembershipId): bool
    {
        $found = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM academic_year_student_enrollment_guards WHERE academic_year_id = ? AND student_membership_id = ?',
            [$academicYearId->toBinary(), $studentMembershipId->toBinary()],
            [ParameterType::BINARY, ParameterType::BINARY],
        );

        return false !== $found && null !== $found;
    }

    private function mapEnrollment(ClassroomStudentEnrollmentException $exception): InstitutionStudentInviteException
    {
        return match ($exception->getReason()) {
            \App\Enum\ClassroomStudentFailureReason::CapacityExceeded => InstitutionStudentInviteException::capacity(),
            \App\Enum\ClassroomStudentFailureReason::Conflict => InstitutionStudentInviteException::notEligible(),
            default => InstitutionStudentInviteException::unavailable(),
        };
    }

    private function normalizeEmail(string $email): string
    {
        try {
            return $this->emailNormalizer->normalize($email);
        } catch (\InvalidArgumentException) {
            throw InstitutionStudentInviteException::invalidInput();
        }
    }

    private function note(string $note): ?string
    {
        $note = trim($note);
        if ('' === $note) {
            return null;
        }
        if (mb_strlen($note) > InstitutionStudentInvitation::NOTE_MAX) {
            throw InstitutionStudentInviteException::invalidInput();
        }

        return $note;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private function resendKey(Uuid $institutionId, Uuid $yearId, string $normalizedEmail): string
    {
        return $institutionId->toRfc4122().':'.$yearId->toRfc4122().':'.$this->rateKeys->hashEmail($normalizedEmail);
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

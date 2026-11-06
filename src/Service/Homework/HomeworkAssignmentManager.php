<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Dto\SecurityAuditContext;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\HomeworkAssignment;
use App\Entity\HomeworkDefinition;
use App\Entity\HomeworkRecipient;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\HomeworkDefinitionStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\StudentEnrollmentStatus;
use App\Exception\HomeworkDefinitionException;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\FreshUserLoader;
use App\Service\InstitutionalFreshEntityLoader;
use App\Service\SecurityAuditRecorder;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Assigns a draft homework definition to its prepared classroom with a recipient snapshot.
 *
 * Lock protocol (same hierarchy as ClassroomStudentEnrollmentManager, then definition, then
 * enrollments/memberships by id): institution WRITE → academic year WRITE → classroom WRITE →
 * definition WRITE → eligible enrollments WRITE (id ASC) → their memberships WRITE (id ASC).
 * Holding the classroom row blocks concurrent enroll/transfer/end that also lock the classroom
 * first. Membership end locks institution then membership; we already hold the institution and
 * re-lock memberships after the enrollment cut, so a membership ending mid-assign either waits
 * or is excluded when re-checked under the membership lock.
 *
 * Snapshot validity point: flush/commit of this transaction under the locks above. The recipient
 * set is whatever enrollments still had an active student membership when those rows were locked
 * and re-checked. Later enrollment/membership changes do not rewrite homework_recipients.
 */
final class HomeworkAssignmentManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FreshUserLoader $freshUsers,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
        private readonly HomeworkTeacherScope $scope,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ClockInterface $clock,
    ) {
    }

    public function assignToClassroom(User $actor, Uuid $definitionId, int $expectedRevision, bool $confirmed): HomeworkAssignment
    {
        $actorId = $actor->getId();

        try {
            $result = $this->entityManager->wrapInTransaction(function () use (
                $actorId,
                $definitionId,
                $expectedRevision,
                $confirmed,
            ): HomeworkAssignment|HomeworkDefinitionException {
                if (!$confirmed) {
                    return HomeworkDefinitionException::confirmationRequired();
                }

                // Peek ids without write locks so we can follow the shared hierarchy order.
                $peek = $this->entityManager->createQueryBuilder()
                    ->select('h', 'c', 'i', 'y')
                    ->from(HomeworkDefinition::class, 'h')
                    ->innerJoin('h.classroom', 'c')
                    ->innerJoin('h.institution', 'i')
                    ->innerJoin('h.academicYear', 'y')
                    ->andWhere('h.id = :id')
                    ->setParameter('id', $definitionId, 'uuid')
                    ->getQuery()
                    ->getOneOrNullResult();
                if (!$peek instanceof HomeworkDefinition) {
                    return HomeworkDefinitionException::notFound();
                }
                $institutionId = $peek->getInstitution()->getId();
                $yearId = $peek->getAcademicYear()->getId();
                $classroomId = $peek->getClassroom()->getId();

                $lockedInstitution = $this->freshEntities->findFreshLockedInstitution($institutionId, LockMode::PESSIMISTIC_WRITE);
                $lockedYear = $this->freshEntities->findFreshLockedAcademicYear($yearId, LockMode::PESSIMISTIC_WRITE);
                $lockedClassroom = $this->freshEntities->findFreshLockedClassroom($classroomId, LockMode::PESSIMISTIC_WRITE);
                if (null === $lockedInstitution || null === $lockedYear || null === $lockedClassroom) {
                    return HomeworkDefinitionException::notFound();
                }
                if (!$lockedClassroom->getInstitution()->getId()->equals($lockedInstitution->getId())
                    || !$lockedClassroom->getAcademicYear()->getId()->equals($lockedYear->getId())) {
                    return HomeworkDefinitionException::notFound();
                }

                $definition = $this->lockDefinition($definitionId);
                $freshActor = $this->freshUsers->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$definition instanceof HomeworkDefinition) {
                    return HomeworkDefinitionException::notFound();
                }
                if (!$freshActor instanceof User || !$this->activeUsers->isActiveAndVerified($freshActor)) {
                    return HomeworkDefinitionException::forbidden();
                }
                if (!$definition->getCreatedByUser()->getId()->equals($freshActor->getId())) {
                    return HomeworkDefinitionException::notFound();
                }
                if (!$definition->getClassroom()->getId()->equals($lockedClassroom->getId())) {
                    return HomeworkDefinitionException::notFound();
                }
                if (HomeworkDefinitionStatus::Draft !== $definition->getStatus()) {
                    return HomeworkDefinitionException::notEditable();
                }
                if ($this->findAssignmentForDefinition($definitionId) instanceof HomeworkAssignment) {
                    return HomeworkDefinitionException::alreadyAssigned();
                }
                if ($expectedRevision !== $definition->getRevisionNumber()) {
                    return HomeworkDefinitionException::staleRevision();
                }

                $teacherAssignment = $this->scope->findAssignment($freshActor, $classroomId, true, true);
                if (null === $teacherAssignment) {
                    return HomeworkDefinitionException::notFound();
                }

                $now = UtcInstant::ensure($this->clock->now());
                $dueAt = $definition->getDueAt();
                if ($dueAt <= $now) {
                    return HomeworkDefinitionException::dueNotFuture();
                }

                $enrollments = $this->lockEligibleEnrollments($classroomId);
                if ([] === $enrollments) {
                    return HomeworkDefinitionException::noRecipients();
                }

                $assignment = HomeworkAssignment::assign(
                    $definition,
                    $freshActor,
                    $teacherAssignment->getTeacherMembership(),
                    $definition->getTitle(),
                    $definition->getDescription(),
                    $dueAt,
                    \count($enrollments),
                    $now,
                );
                $this->entityManager->persist($assignment);
                foreach ($enrollments as $enrollment) {
                    $this->entityManager->persist(HomeworkRecipient::fromEnrollment($assignment, $enrollment, $now));
                }
                $this->entityManager->flush();
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::HomeworkAssignmentCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'resource_type' => 'homework_assignment',
                        'resource_id' => $assignment->getId()->toRfc4122(),
                        'definition_id' => $definition->getId()->toRfc4122(),
                        'institution_id' => $assignment->getInstitution()->getId()->toRfc4122(),
                        'classroom_id' => $assignment->getClassroom()->getId()->toRfc4122(),
                        'academic_year_id' => $assignment->getAcademicYear()->getId()->toRfc4122(),
                        'status' => $assignment->getStatus()->value,
                        'recipient_count' => $assignment->getRecipientCount(),
                        'revision_number' => $definition->getRevisionNumber(),
                    ],
                    correlationId: Uuid::v7()->toRfc4122(),
                ));

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            throw HomeworkDefinitionException::alreadyAssigned();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw HomeworkDefinitionException::conflict();
        }

        if ($result instanceof HomeworkDefinitionException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Eligible active enrollments under classroom lock, then membership locks, ordered by enrollment id.
     *
     * @return list<ClassroomStudentEnrollment>
     */
    private function lockEligibleEnrollments(Uuid $classroomId): array
    {
        /** @var list<ClassroomStudentEnrollment> $enrollments */
        $enrollments = $this->entityManager->createQueryBuilder()
            ->select('e', 'm', 'u')
            ->from(ClassroomStudentEnrollment::class, 'e')
            ->innerJoin('e.studentMembership', 'm')
            ->innerJoin('m.user', 'u')
            ->andWhere('e.classroom = :classroom')
            ->andWhere('e.status = :enrollmentStatus')
            ->andWhere('m.status = :membershipStatus')
            ->andWhere('m.role = :membershipRole')
            ->setParameter('classroom', $classroomId, 'uuid')
            ->setParameter('enrollmentStatus', StudentEnrollmentStatus::Active)
            ->setParameter('membershipStatus', InstitutionMembershipStatus::Active)
            ->setParameter('membershipRole', InstitutionMembershipRole::Student)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        $membershipIds = [];
        foreach ($enrollments as $enrollment) {
            $membershipIds[$enrollment->getStudentMembership()->getId()->toRfc4122()] = $enrollment->getStudentMembership()->getId();
        }
        ksort($membershipIds);
        foreach ($membershipIds as $membershipId) {
            $locked = $this->freshEntities->findFreshLockedMembership($membershipId, LockMode::PESSIMISTIC_WRITE);
            if (!$locked instanceof InstitutionMembership
                || InstitutionMembershipStatus::Active !== $locked->getStatus()
                || InstitutionMembershipRole::Student !== $locked->getRole()
            ) {
                // Membership ended under us: drop enrollments tied to it.
                $enrollments = array_values(array_filter(
                    $enrollments,
                    static fn (ClassroomStudentEnrollment $row): bool => !$row->getStudentMembership()->getId()->equals($membershipId),
                ));
            }
        }

        return $enrollments;
    }

    private function lockDefinition(Uuid $definitionId): ?HomeworkDefinition
    {
        $definition = $this->entityManager->createQueryBuilder()
            ->select('h')
            ->from(HomeworkDefinition::class, 'h')
            ->andWhere('h.id = :id')
            ->setParameter('id', $definitionId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $definition instanceof HomeworkDefinition ? $definition : null;
    }

    private function findAssignmentForDefinition(Uuid $definitionId): ?HomeworkAssignment
    {
        $assignment = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(HomeworkAssignment::class, 'a')
            ->andWhere('a.definition = :definition')
            ->setParameter('definition', $definitionId, 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $assignment instanceof HomeworkAssignment ? $assignment : null;
    }
}

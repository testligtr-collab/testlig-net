<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\InstitutionAssignableTeacher;
use App\Entity\Classroom;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\TeacherAssignmentRole;
use App\Enum\TeacherAssignmentStatus;
use App\Exception\ClassroomTeacherAssignmentException;
use App\Repository\InstitutionMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Institution-panel teacher assignment. Membership is checked here, then the canonical manager runs the mutation.
 * Global roles, including SuperAdmin, do not pass this check.
 */
final class InstitutionClassroomTeacherEditor
{
    public function __construct(
        private readonly ClassroomTeacherAssignmentManager $assignments,
        private readonly InstitutionMembershipRepository $memberships,
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<InstitutionAssignableTeacher>
     */
    public function candidates(User $actor, Institution $institution, string $classroomReference): array
    {
        $this->assertLeader($actor, $institution);
        $classroom = $this->classroomFor($institution, $classroomReference);
        if (!$classroom instanceof Classroom || !$this->canAssign($classroom)) {
            return [];
        }
        $busy = $this->activeMembershipIds($classroom);
        $list = [];
        foreach ($this->activeTeachers($institution) as $membership) {
            if (isset($busy[$membership->getId()->toRfc4122()])) {
                continue;
            }
            $user = $membership->getUser();
            $list[] = new InstitutionAssignableTeacher(
                $this->hasher->workspaceReference('membership', $membership->getId()),
                trim($user->getFirstName().' '.$user->getLastName()),
            );
        }

        return $list;
    }

    public function assign(
        User $actor,
        Institution $institution,
        string $classroomReference,
        string $membershipReference,
        string $roleValue,
    ): void {
        $this->assertLeader($actor, $institution);
        $classroom = $this->classroomFor($institution, $classroomReference);
        if (!$classroom instanceof Classroom) {
            throw ClassroomTeacherAssignmentException::notFound();
        }
        $membership = $this->membershipFor($institution, $membershipReference);
        if (!$membership instanceof InstitutionMembership) {
            throw ClassroomTeacherAssignmentException::notFound();
        }
        $role = TeacherAssignmentRole::tryFrom($roleValue);
        if (!$role instanceof TeacherAssignmentRole) {
            throw ClassroomTeacherAssignmentException::invalidInput();
        }
        $this->assignments->assign($classroom, $actor, $membership, $role, 'panel_assign');
    }

    public function end(User $actor, Institution $institution, string $classroomReference, string $assignmentReference): void
    {
        $this->assertLeader($actor, $institution);
        $classroom = $this->classroomFor($institution, $classroomReference);
        if (!$classroom instanceof Classroom) {
            throw ClassroomTeacherAssignmentException::notFound();
        }
        $assignment = $this->assignmentFor($classroom, $assignmentReference);
        if (!$assignment instanceof ClassroomTeacherAssignment) {
            throw ClassroomTeacherAssignmentException::notFound();
        }
        $this->assignments->endAssignment($assignment, $actor, 'panel_end');
    }

    private function assertLeader(User $actor, Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw ClassroomTeacherAssignmentException::unauthorized();
        }
        $membership = $this->memberships->findActiveMembership($actor, $institution);
        if (!$membership instanceof InstitutionMembership) {
            throw ClassroomTeacherAssignmentException::unauthorized();
        }
        $role = $membership->getRole();
        if (InstitutionMembershipRole::Owner !== $role && InstitutionMembershipRole::Manager !== $role) {
            throw ClassroomTeacherAssignmentException::unauthorized();
        }
    }

    private function canAssign(Classroom $classroom): bool
    {
        return ClassroomStatus::Active === $classroom->getStatus()
            && AcademicYearStatus::Closed !== $classroom->getAcademicYear()->getStatus();
    }

    /**
     * @return array<string, true>
     */
    private function activeMembershipIds(Classroom $classroom): array
    {
        /** @var list<array{id: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(a.teacherMembership) AS id')
            ->from(ClassroomTeacherAssignment::class, 'a')
            ->andWhere('a.classroom = :classroom')
            ->andWhere('a.institution = :institution')
            ->andWhere('a.status = :status')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->setParameter('institution', $classroom->getInstitution()->getId(), 'uuid')
            ->setParameter('status', TeacherAssignmentStatus::Active)
            ->getQuery()
            ->getArrayResult();
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['id'] instanceof Uuid ? $row['id']->toRfc4122() : (\is_string($row['id']) ? $row['id'] : '');
            if ('' !== $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @return list<InstitutionMembership>
     */
    private function activeTeachers(Institution $institution): array
    {
        /** @var list<InstitutionMembership> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('m', 'u')
            ->from(InstitutionMembership::class, 'm')
            ->innerJoin('m.user', 'u')
            ->andWhere('m.institution = :institution')
            ->andWhere('m.role = :role')
            ->andWhere('m.status = :status')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('role', InstitutionMembershipRole::Teacher)
            ->setParameter('status', InstitutionMembershipStatus::Active)
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    private function classroomFor(Institution $institution, string $reference): ?Classroom
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
            $id = $this->uuid($row['id']);
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

    private function membershipFor(Institution $institution, string $reference): ?InstitutionMembership
    {
        $reference = strtolower(trim($reference));
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return null;
        }
        foreach ($this->activeTeachers($institution) as $membership) {
            if (hash_equals($this->hasher->workspaceReference('membership', $membership->getId()), $reference)) {
                return $membership;
            }
        }

        return null;
    }

    private function assignmentFor(Classroom $classroom, string $reference): ?ClassroomTeacherAssignment
    {
        $reference = strtolower(trim($reference));
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return null;
        }
        /** @var list<array{id: mixed}> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('a.id AS id')
            ->from(ClassroomTeacherAssignment::class, 'a')
            ->andWhere('a.classroom = :classroom')
            ->andWhere('a.institution = :institution')
            ->andWhere('a.status = :status')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->setParameter('institution', $classroom->getInstitution()->getId(), 'uuid')
            ->setParameter('status', TeacherAssignmentStatus::Active)
            ->getQuery()
            ->getArrayResult();
        foreach ($ids as $row) {
            $id = $this->uuid($row['id']);
            if (!$id instanceof Uuid || !hash_equals($this->hasher->workspaceReference('teacher_assignment', $id), $reference)) {
                continue;
            }
            $assignment = $this->entityManager->find(ClassroomTeacherAssignment::class, $id);

            return $assignment instanceof ClassroomTeacherAssignment
                && $assignment->getClassroom()->getId()->equals($classroom->getId())
                ? $assignment
                : null;
        }

        return null;
    }

    private function uuid(mixed $id): ?Uuid
    {
        if ($id instanceof Uuid) {
            return $id;
        }
        if (\is_string($id) && Uuid::isValid($id)) {
            return Uuid::fromString($id);
        }

        return null;
    }
}

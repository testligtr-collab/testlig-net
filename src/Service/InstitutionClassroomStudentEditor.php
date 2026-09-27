<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Classroom;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\StudentProfile;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\StudentEnrollmentStatus;
use App\Exception\ClassroomStudentEnrollmentException;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\StudentProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Institution-panel enrollment changes. Leadership is checked here before the canonical manager runs.
 * Global roles, including SuperAdmin, do not pass this check. Ending a class does not end the membership.
 */
final class InstitutionClassroomStudentEditor
{
    public function __construct(
        private readonly ClassroomStudentEnrollmentManager $enrollments,
        private readonly InstitutionMembershipRepository $memberships,
        private readonly StudentProfileRepository $profiles,
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function end(User $actor, Institution $institution, string $classroomReference, string $enrollmentReference): void
    {
        $this->assertLeader($actor, $institution);
        $enrollment = $this->enrollmentFor($institution, $classroomReference, $enrollmentReference);
        if (!$enrollment instanceof ClassroomStudentEnrollment) {
            throw ClassroomStudentEnrollmentException::notFound();
        }
        $this->enrollments->endEnrollment($enrollment, $actor, 'panel_end');
    }

    public function transfer(
        User $actor,
        Institution $institution,
        string $classroomReference,
        string $enrollmentReference,
        string $targetReference,
    ): void {
        $this->assertLeader($actor, $institution);
        $enrollment = $this->enrollmentFor($institution, $classroomReference, $enrollmentReference);
        if (!$enrollment instanceof ClassroomStudentEnrollment) {
            throw ClassroomStudentEnrollmentException::notFound();
        }
        $target = $this->classroomFor($institution, $targetReference);
        if (!$target instanceof Classroom) {
            throw ClassroomStudentEnrollmentException::notFound();
        }
        $profile = $this->profiles->findOneByUser($enrollment->getStudentMembership()->getUser());
        if (!$profile instanceof StudentProfile || !$profile->isOnboardingCompleted() || $profile->getGradeLevel() !== $target->getGradeLevel()) {
            throw ClassroomStudentEnrollmentException::invalidInput('Classroom grade does not match the student profile.');
        }
        $this->enrollments->transfer($enrollment, $actor, $target, 'panel_transfer');
    }

    private function assertLeader(User $actor, Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw ClassroomStudentEnrollmentException::unauthorized();
        }
        $membership = $this->memberships->findActiveMembership($actor, $institution);
        if (!$membership instanceof InstitutionMembership) {
            throw ClassroomStudentEnrollmentException::unauthorized();
        }
        $role = $membership->getRole();
        if (InstitutionMembershipRole::Owner !== $role && InstitutionMembershipRole::Manager !== $role) {
            throw ClassroomStudentEnrollmentException::unauthorized();
        }
    }

    private function enrollmentFor(Institution $institution, string $classroomReference, string $enrollmentReference): ?ClassroomStudentEnrollment
    {
        $classroom = $this->classroomFor($institution, $classroomReference);
        if (!$classroom instanceof Classroom) {
            return null;
        }
        $enrollmentReference = strtolower(trim($enrollmentReference));
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $enrollmentReference)) {
            return null;
        }
        /** @var list<array{id: mixed}> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('e.id AS id')
            ->from(ClassroomStudentEnrollment::class, 'e')
            ->andWhere('e.classroom = :classroom')
            ->andWhere('e.institution = :institution')
            ->andWhere('e.status = :status')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('status', StudentEnrollmentStatus::Active)
            ->getQuery()
            ->getArrayResult();
        foreach ($ids as $row) {
            $id = $this->uuid($row['id']);
            if (!$id instanceof Uuid || !hash_equals($this->hasher->workspaceReference('student_enrollment', $id), $enrollmentReference)) {
                continue;
            }
            $enrollment = $this->entityManager->find(ClassroomStudentEnrollment::class, $id);

            return $enrollment instanceof ClassroomStudentEnrollment
                && $enrollment->getClassroom()->getId()->equals($classroom->getId())
                ? $enrollment
                : null;
        }

        return null;
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

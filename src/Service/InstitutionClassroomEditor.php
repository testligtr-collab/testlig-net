<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\InstitutionAcademicYearOption;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Exception\ClassroomException;
use App\Repository\AcademicYearRepository;
use App\Repository\InstitutionMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Institution-panel classroom writes. Membership is checked here, then {@see ClassroomManager} runs the mutation.
 * Global roles, including SuperAdmin, do not pass this check.
 */
final class InstitutionClassroomEditor
{
    public function __construct(
        private readonly ClassroomManager $classrooms,
        private readonly AcademicYearRepository $years,
        private readonly InstitutionMembershipRepository $memberships,
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<InstitutionAcademicYearOption>
     */
    public function operableYears(Institution $institution): array
    {
        $options = [];
        foreach ($this->years->findOperableForInstitution($institution) as $year) {
            $options[] = new InstitutionAcademicYearOption(
                $this->hasher->workspaceReference('academic_year', $year->getId()),
                $year->getName(),
                $this->yearStatusLabel($year),
            );
        }

        return $options;
    }

    public function create(
        User $actor,
        Institution $institution,
        string $yearReference,
        string $name,
        GradeLevel $gradeLevel,
        ?string $sectionCode,
        ?int $capacity,
    ): string {
        $this->assertLeader($actor, $institution);
        $year = $this->yearFor($institution, $yearReference);
        if (!$year instanceof AcademicYear) {
            throw ClassroomException::notFound();
        }
        $classroom = $this->classrooms->create($year, $actor, $name, $gradeLevel, 'panel_create', $sectionCode, $capacity);

        return $this->hasher->workspaceReference('classroom', $classroom->getId());
    }

    public function revise(
        User $actor,
        Institution $institution,
        string $reference,
        string $name,
        ?int $capacity,
        string $updatedAtToken,
    ): void {
        $this->assertLeader($actor, $institution);
        $classroom = $this->classroomFor($institution, $reference);
        if (!$classroom instanceof Classroom) {
            throw ClassroomException::notFound();
        }
        if (1 !== preg_match('/^\d{1,12}$/', $updatedAtToken)) {
            throw ClassroomException::conflict();
        }
        $this->classrooms->revise($classroom, $actor, $name, $capacity, (int) $updatedAtToken, 'panel_revise');
    }

    public function archive(User $actor, Institution $institution, string $reference): void
    {
        $this->assertLeader($actor, $institution);
        $classroom = $this->classroomFor($institution, $reference);
        if (!$classroom instanceof Classroom) {
            throw ClassroomException::notFound();
        }
        $this->classrooms->archive($classroom, $actor, 'panel_archive');
    }

    private function assertLeader(User $actor, Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw ClassroomException::unauthorized();
        }
        $membership = $this->memberships->findActiveMembership($actor, $institution);
        if (!$membership instanceof InstitutionMembership) {
            throw ClassroomException::unauthorized();
        }
        $role = $membership->getRole();
        if (InstitutionMembershipRole::Owner !== $role && InstitutionMembershipRole::Manager !== $role) {
            throw ClassroomException::unauthorized();
        }
    }

    private function yearFor(Institution $institution, string $reference): ?AcademicYear
    {
        $reference = strtolower(trim($reference));
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return null;
        }
        foreach ($this->years->findOperableForInstitution($institution) as $year) {
            if (hash_equals($this->hasher->workspaceReference('academic_year', $year->getId()), $reference)) {
                return $year;
            }
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

    private function yearStatusLabel(AcademicYear $year): string
    {
        return match ($year->getStatus()) {
            AcademicYearStatus::Active => 'Aktif',
            AcademicYearStatus::Planned => 'Planlandı',
            AcademicYearStatus::Closed => 'Kapandı',
        };
    }
}

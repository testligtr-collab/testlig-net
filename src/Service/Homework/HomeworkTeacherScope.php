<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Dto\HomeworkClassroomOption;
use App\Entity\Classroom;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\TeacherAssignmentStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Current (not historical) teacher scope for draft homework.
 *
 * A global ROLE_* never grants scope. Admin and SuperAdmin hierarchy is not consulted.
 * Every call reads the database; callers must not cache results across a write.
 */
final class HomeworkTeacherScope
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Active teacher membership + active classroom teacher assignment on an active institution.
     * With $openScope the classroom and its academic year must also be active (needed for writes).
     */
    public function findAssignment(User $actor, Uuid $classroomId, bool $openScope, bool $lock = false): ?ClassroomTeacherAssignment
    {
        $qb = $this->baseQuery($actor, $openScope)
            ->select('a')
            ->andWhere('c.id = :classroom')
            ->setParameter('classroom', $classroomId, 'uuid')
            ->setMaxResults(1);
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_READ);
        }
        $rows = $query->getResult();
        $assignment = $rows[0] ?? null;

        return $assignment instanceof ClassroomTeacherAssignment ? $assignment : null;
    }

    public function findMembership(User $actor, Uuid $classroomId, bool $openScope, bool $lock = false): ?InstitutionMembership
    {
        return $this->findAssignment($actor, $classroomId, $openScope, $lock)?->getTeacherMembership();
    }

    public function findClassroom(User $actor, Uuid $classroomId, bool $openScope, bool $lock = false): ?Classroom
    {
        return $this->findAssignment($actor, $classroomId, $openScope, $lock)?->getClassroom();
    }

    /**
     * Classrooms the actor currently teaches. One query; labels are joined (no N+1).
     * With $openScope only classrooms that can take new drafts (active classroom and academic year).
     *
     * @return list<HomeworkClassroomOption>
     */
    public function classrooms(User $actor, bool $openScope): array
    {
        /** @var list<array{id: Uuid|string, name: string, institutionName: string}> $rows */
        $rows = $this->baseQuery($actor, $openScope)
            ->select('c.id AS id', 'c.name AS name', 'i.name AS institutionName')
            ->distinct()
            ->orderBy('i.name', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $options = [];
        foreach ($rows as $row) {
            $options[] = new HomeworkClassroomOption(self::uuid($row['id']), $row['name'], $row['institutionName']);
        }

        return $options;
    }

    /**
     * @param list<HomeworkClassroomOption> $options
     */
    public static function spansInstitutions(array $options): bool
    {
        $names = [];
        foreach ($options as $option) {
            $names[$option->institutionName] = true;
        }

        return \count($names) > 1;
    }

    private static function uuid(Uuid|string $value): string
    {
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }

        return 16 === \strlen($value) ? Uuid::fromBinary($value)->toRfc4122() : Uuid::fromString($value)->toRfc4122();
    }

    private function baseQuery(User $actor, bool $openScope): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(ClassroomTeacherAssignment::class, 'a')
            ->innerJoin('a.teacherMembership', 'm')
            ->innerJoin('a.classroom', 'c')
            ->innerJoin('c.institution', 'i')
            ->innerJoin('c.academicYear', 'y')
            ->andWhere('a.status = :assignmentStatus')
            ->andWhere('m.user = :actor')
            ->andWhere('m.institution = i')
            ->andWhere('m.status = :membershipStatus')
            ->andWhere('m.role = :membershipRole')
            ->andWhere('i.status = :institutionStatus')
            ->setParameter('assignmentStatus', TeacherAssignmentStatus::Active)
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->setParameter('membershipStatus', InstitutionMembershipStatus::Active)
            ->setParameter('membershipRole', InstitutionMembershipRole::Teacher)
            ->setParameter('institutionStatus', InstitutionStatus::Active);
        if ($openScope) {
            $qb->andWhere('c.status = :classroomStatus')
                ->andWhere('y.status = :yearStatus')
                ->setParameter('classroomStatus', ClassroomStatus::Active)
                ->setParameter('yearStatus', AcademicYearStatus::Active);
        }

        return $qb;
    }
}

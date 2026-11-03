<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Dto\HomeworkDraftPage;
use App\Dto\HomeworkDraftRow;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\HomeworkDefinition;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomStatus;
use App\Enum\HomeworkDefinitionStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\TeacherAssignmentStatus;
use App\Time\UtcInstant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Read model for a teacher's own draft homework.
 *
 * A row is visible only when ALL hold now: created_by_user_id is the actor, the actor has a
 * current active teacher membership in the institution, and a current active
 * ClassroomTeacherAssignment on that classroom. Ended assignments hide history. Global admin
 * roles are never consulted.
 */
final class HomeworkDefinitionQuery
{
    public const PAGE_SIZE = 20;
    public const SEARCH_MAX = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function page(User $actor, string $search, ?Uuid $classroomId, int $page): HomeworkDraftPage
    {
        $total = $this->count($actor, $search, $classroomId);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pageCount);

        /** @var list<array{id: Uuid|string, title: string, dueAt: \DateTimeInterface, classroomName: string, institutionName: string}> $rows */
        $rows = $this->filtered($actor, $search, $classroomId)
            ->select(
                'h.id AS id',
                'h.title AS title',
                'h.dueAt AS dueAt',
                'c.name AS classroomName',
                'i.name AS institutionName',
            )
            ->orderBy('h.updatedAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getArrayResult();

        $list = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            $list[] = new HomeworkDraftRow(
                $id instanceof Uuid ? $id->toRfc4122() : (16 === \strlen($id) ? Uuid::fromBinary($id)->toRfc4122() : $id),
                $row['title'],
                UtcInstant::ensure($row['dueAt']),
                $row['classroomName'],
                $row['institutionName'],
            );
        }

        return new HomeworkDraftPage($list, $total, $page, $pageCount);
    }

    /**
     * Draft count for the same authorization and filter as {@see page()}.
     */
    public function count(User $actor, string $search = '', ?Uuid $classroomId = null): int
    {
        return (int) $this->filtered($actor, $search, $classroomId)
            ->select('COUNT(h.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * One authorized draft with classroom, institution and year loaded in a single query.
     * With $openScope the classroom and academic year must still be active (edit).
     */
    public function findOwned(User $actor, Uuid $id, bool $openScope = false): ?HomeworkDefinition
    {
        $qb = $this->authorized($actor)
            ->select('h', 'c', 'i', 'y')
            ->innerJoin('h.academicYear', 'y')
            ->andWhere('h.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->setMaxResults(1);
        if ($openScope) {
            $qb->andWhere('c.status = :classroomStatus')
                ->andWhere('y.status = :yearStatus')
                ->setParameter('classroomStatus', ClassroomStatus::Active)
                ->setParameter('yearStatus', AcademicYearStatus::Active);
        }
        $result = $qb->getQuery()->getResult();
        $definition = $result[0] ?? null;

        return $definition instanceof HomeworkDefinition ? $definition : null;
    }

    private function filtered(User $actor, string $search, ?Uuid $classroomId): QueryBuilder
    {
        $qb = $this->authorized($actor);
        $search = trim(mb_substr(trim($search), 0, self::SEARCH_MAX));
        if ('' !== $search) {
            $qb->andWhere('h.title LIKE :search')
                ->setParameter('search', '%'.addcslashes($search, '\\%_').'%');
        }
        if ($classroomId instanceof Uuid) {
            $qb->andWhere('c.id = :filterClassroom')
                ->setParameter('filterClassroom', $classroomId, 'uuid');
        }

        return $qb;
    }

    private function authorized(User $actor): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(HomeworkDefinition::class, 'h')
            ->innerJoin('h.classroom', 'c')
            ->innerJoin('h.institution', 'i');
        $current = $this->entityManager->createQueryBuilder()
            ->select('a.id')
            ->from(ClassroomTeacherAssignment::class, 'a')
            ->innerJoin('a.teacherMembership', 'm')
            ->andWhere('a.classroom = h.classroom')
            ->andWhere('a.status = :assignmentStatus')
            ->andWhere('m.user = :actor')
            ->andWhere('m.institution = h.institution')
            ->andWhere('m.status = :membershipStatus')
            ->andWhere('m.role = :membershipRole')
            ->getDQL();

        return $qb
            ->andWhere('h.createdByUser = :actor')
            ->andWhere('h.status = :status')
            ->andWhere('i.status = :institutionStatus')
            ->andWhere($qb->expr()->exists($current))
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->setParameter('status', HomeworkDefinitionStatus::Draft)
            ->setParameter('institutionStatus', InstitutionStatus::Active)
            ->setParameter('assignmentStatus', TeacherAssignmentStatus::Active)
            ->setParameter('membershipStatus', InstitutionMembershipStatus::Active)
            ->setParameter('membershipRole', InstitutionMembershipRole::Teacher);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Dto\HomeworkDraftPage;
use App\Dto\HomeworkDraftRow;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\HomeworkAssignment;
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
 * Read model for a teacher's own homework definitions (draft and assigned).
 *
 * A row is visible only when ALL hold now: created_by_user_id is the actor, the actor has a
 * current active teacher membership in the institution, and a current active
 * ClassroomTeacherAssignment on that classroom. Ended assignments hide history. Global admin
 * roles are never consulted.
 *
 * UI "Atandı" comes from the presence of HomeworkAssignment, not from changing definition.status.
 */
final class HomeworkDefinitionQuery
{
    public const PAGE_SIZE = 20;
    public const SEARCH_MAX = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function page(User $actor, string $search, ?Uuid $classroomId, int $page, ?bool $assignedOnly = null): HomeworkDraftPage
    {
        $total = $this->count($actor, $search, $classroomId, $assignedOnly);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pageCount);
        $counts = $this->statusCounts($actor);

        /** @var list<array{id: Uuid|string, title: string, dueAt: \DateTimeInterface, classroomName: string, institutionName: string, assignmentId: Uuid|string|null, recipientCount: int|null}> $rows */
        $rows = $this->filtered($actor, $search, $classroomId, $assignedOnly)
            ->select(
                'h.id AS id',
                'COALESCE(asg.title, h.title) AS title',
                'COALESCE(asg.dueAt, h.dueAt) AS dueAt',
                'c.name AS classroomName',
                'i.name AS institutionName',
                'asg.id AS assignmentId',
                'asg.recipientCount AS recipientCount',
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
            $assigned = null !== $row['assignmentId'] && '' !== $row['assignmentId'];
            $list[] = new HomeworkDraftRow(
                $id instanceof Uuid ? $id->toRfc4122() : (16 === \strlen((string) $id) ? Uuid::fromBinary((string) $id)->toRfc4122() : (string) $id),
                $row['title'],
                UtcInstant::ensure($row['dueAt']),
                $row['classroomName'],
                $row['institutionName'],
                $assigned,
                $assigned ? (int) $row['recipientCount'] : null,
            );
        }

        return new HomeworkDraftPage($list, $total, $page, $pageCount, $counts['draft'], $counts['assigned']);
    }

    /**
     * Count for the same authorization and filter as {@see page()}.
     */
    public function count(User $actor, string $search = '', ?Uuid $classroomId = null, ?bool $assignedOnly = null): int
    {
        return (int) $this->filtered($actor, $search, $classroomId, $assignedOnly)
            ->select('COUNT(h.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array{draft: int, assigned: int}
     */
    public function statusCounts(User $actor): array
    {
        $rows = $this->authorized($actor)
            ->select('COUNT(h.id) AS total', 'COUNT(asg.id) AS assigned')
            ->getQuery()
            ->getSingleResult();

        $total = (int) ($rows['total'] ?? 0);
        $assigned = (int) ($rows['assigned'] ?? 0);

        return ['draft' => max(0, $total - $assigned), 'assigned' => $assigned];
    }

    /**
     * One authorized definition with classroom, institution and year loaded in a single query.
     * With $openScope the classroom and academic year must still be active (edit/assign).
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

    public function findAssignment(User $actor, Uuid $definitionId): ?HomeworkAssignment
    {
        if (!$this->findOwned($actor, $definitionId, false) instanceof HomeworkDefinition) {
            return null;
        }
        $assignment = $this->entityManager->createQueryBuilder()
            ->select('asg', 'c', 'i', 'y')
            ->from(HomeworkAssignment::class, 'asg')
            ->innerJoin('asg.classroom', 'c')
            ->innerJoin('asg.institution', 'i')
            ->innerJoin('asg.academicYear', 'y')
            ->andWhere('asg.definition = :id')
            ->setParameter('id', $definitionId, 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $assignment instanceof HomeworkAssignment ? $assignment : null;
    }

    /**
     * Eligible active student count for assign confirmation (no names). Not a snapshot.
     */
    public function countEligibleRecipients(Uuid $classroomId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(\App\Entity\ClassroomStudentEnrollment::class, 'e')
            ->innerJoin('e.studentMembership', 'm')
            ->andWhere('e.classroom = :classroom')
            ->andWhere('e.status = :enrollmentStatus')
            ->andWhere('m.status = :membershipStatus')
            ->andWhere('m.role = :membershipRole')
            ->setParameter('classroom', $classroomId, 'uuid')
            ->setParameter('enrollmentStatus', \App\Enum\StudentEnrollmentStatus::Active)
            ->setParameter('membershipStatus', InstitutionMembershipStatus::Active)
            ->setParameter('membershipRole', InstitutionMembershipRole::Student)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function filtered(User $actor, string $search, ?Uuid $classroomId, ?bool $assignedOnly): QueryBuilder
    {
        $qb = $this->authorized($actor);
        $search = trim(mb_substr(trim($search), 0, self::SEARCH_MAX));
        if ('' !== $search) {
            $qb->andWhere('(h.title LIKE :search OR asg.title LIKE :search)')
                ->setParameter('search', '%'.addcslashes($search, '\\%_').'%');
        }
        if ($classroomId instanceof Uuid) {
            $qb->andWhere('c.id = :filterClassroom')
                ->setParameter('filterClassroom', $classroomId, 'uuid');
        }
        if (true === $assignedOnly) {
            $qb->andWhere('asg.id IS NOT NULL');
        } elseif (false === $assignedOnly) {
            $qb->andWhere('asg.id IS NULL');
        }

        return $qb;
    }

    private function authorized(User $actor): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(HomeworkDefinition::class, 'h')
            ->innerJoin('h.classroom', 'c')
            ->innerJoin('h.institution', 'i')
            ->leftJoin(HomeworkAssignment::class, 'asg', 'WITH', 'asg.definition = h');
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

<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminClassroomDetailView;
use App\Dto\AdminClassroomInstitutionChoice;
use App\Dto\AdminClassroomListItemView;
use App\Dto\AdminClassroomListSummary;
use App\Dto\AdminClassroomTeacherView;
use App\Dto\AdminClassroomYearChoice;
use App\Dto\AdminPagedResult;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\Institution;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomStatus;
use App\Enum\InstitutionStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentRole;
use App\Enum\TeacherAssignmentStatus;
use App\Exception\CommerceException;
use App\Security\AdminAuthorization;
use App\Service\InstitutionalFreshEntityLoader;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Allowlisted classroom queries. Counters ignore the active filters.
 */
final class AdminClassroomQuery
{
    private const ALLOWED_FILTER_KEYS = [
        'q',
        'institution',
        'year',
        'status',
        'page',
        'page_size',
    ];

    public function __construct(
        private readonly AdminActorGuard $actorGuard,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $rawFilters
     *
     * @return AdminPagedResult<AdminClassroomListItemView>
     */
    public function listClassrooms(Uuid $actorId, array $rawFilters = []): AdminPagedResult
    {
        $this->actorGuard->requireClassroomsView($actorId);
        $filters = $this->normalizeFilters($rawFilters);
        $page = AdminPagination::normalizePage((int) ($filters['page'] ?? 1));
        $pageSize = AdminPagination::normalizePageSize((int) ($filters['page_size'] ?? AdminPagination::DEFAULT_PAGE_SIZE));

        $qb = $this->em->createQueryBuilder()
            ->select('c', 'i', 'y')
            ->from(Classroom::class, 'c')
            ->join('c.institution', 'i')
            ->join('c.academicYear', 'y');
        $this->applyFilters($qb, $filters);

        $countQb = clone $qb;
        $total = (int) $countQb
            ->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<array{0: Classroom, activeStudents: int|string}|Classroom> $rows */
        $rows = $qb
            ->addSelect('(SELECT COUNT(en.id) FROM '.ClassroomStudentEnrollment::class.' en WHERE en.classroom = c AND en.status = :enrollActive) AS activeStudents')
            ->setParameter('enrollActive', StudentEnrollmentStatus::Active)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $classrooms = [];
        $studentCounts = [];
        foreach ($rows as $row) {
            if ($row instanceof Classroom) {
                $classrooms[] = $row;
                $studentCounts[$row->getId()->toRfc4122()] = 0;
                continue;
            }
            $classroom = $row[0];
            $classrooms[] = $classroom;
            $studentCounts[$classroom->getId()->toRfc4122()] = (int) $row['activeStudents'];
        }
        $teachers = $this->activeTeachers($classrooms);

        $items = [];
        foreach ($classrooms as $classroom) {
            $id = $classroom->getId()->toRfc4122();
            $assigned = $teachers[$id] ?? [];
            $items[] = new AdminClassroomListItemView(
                id: $id,
                name: $classroom->getName(),
                sectionCode: $classroom->getSectionCode(),
                gradeLabel: self::gradeLabel($classroom),
                status: $classroom->getStatus(),
                institutionId: $classroom->getInstitution()->getId()->toRfc4122(),
                institutionName: $classroom->getInstitution()->getName(),
                academicYearId: $classroom->getAcademicYear()->getId()->toRfc4122(),
                academicYearName: $classroom->getAcademicYear()->getName(),
                teacherSummary: self::teacherSummary($assigned),
                activeStudentCount: $studentCounts[$id] ?? 0,
            );
        }

        return new AdminPagedResult($items, $page, $pageSize, $total);
    }

    public function summarize(Uuid $actorId): AdminClassroomListSummary
    {
        $this->actorGuard->requireClassroomsView($actorId);

        /** @var list<array{status: ClassroomStatus|string, cnt: string|int}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('c.status AS status, COUNT(c.id) AS cnt')
            ->from(Classroom::class, 'c')
            ->groupBy('c.status')
            ->getQuery()
            ->getArrayResult();

        $total = 0;
        $active = 0;
        foreach ($rows as $row) {
            $count = (int) $row['cnt'];
            $total += $count;
            $status = $row['status'] instanceof ClassroomStatus ? $row['status']->value : (string) $row['status'];
            if (ClassroomStatus::Active->value === $status) {
                $active = $count;
            }
        }

        $withoutTeacher = (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Classroom::class, 'c')
            ->andWhere('c.status = :active')
            ->andWhere('NOT EXISTS (SELECT a.id FROM '.ClassroomTeacherAssignment::class.' a WHERE a.classroom = c AND a.status = :assigned)')
            ->setParameter('active', ClassroomStatus::Active)
            ->setParameter('assigned', TeacherAssignmentStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();

        $linked = (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT i.id)')
            ->from(Classroom::class, 'c')
            ->join('c.institution', 'i')
            ->getQuery()
            ->getSingleScalarResult();

        return new AdminClassroomListSummary($total, $active, $withoutTeacher, $linked);
    }

    public function getDetail(Uuid $actorId, Uuid $classroomId): AdminClassroomDetailView
    {
        $actor = $this->actorGuard->requireClassroomsView($actorId);

        return $this->em->wrapInTransaction(function () use ($actor, $classroomId): AdminClassroomDetailView {
            $classroom = $this->freshEntities->findFreshLockedClassroom($classroomId, LockMode::PESSIMISTIC_READ);
            if (!$classroom instanceof Classroom) {
                throw CommerceException::notFound();
            }
            $institution = $this->freshEntities->findFreshLockedInstitution($classroom->getInstitution()->getId(), LockMode::PESSIMISTIC_READ);
            $year = $this->freshEntities->findFreshLockedAcademicYear($classroom->getAcademicYear()->getId(), LockMode::PESSIMISTIC_READ);
            if (!$institution instanceof Institution || !$year instanceof AcademicYear) {
                throw CommerceException::notFound();
            }
            if (!$classroom->getInstitution()->getId()->equals($institution->getId())
                || !$classroom->getAcademicYear()->getId()->equals($year->getId())
                || !$year->getInstitution()->getId()->equals($institution->getId())) {
                throw CommerceException::notFound();
            }

            $teachers = $this->activeTeachers([$classroom])[$classroom->getId()->toRfc4122()] ?? [];
            $students = $this->enrollmentCounts($classroom);
            $canManage = $this->adminAuthorization->canManageClassrooms($actor);
            $institutionActive = InstitutionStatus::Active === $institution->getStatus();
            $classroomActive = ClassroomStatus::Active === $classroom->getStatus();

            return new AdminClassroomDetailView(
                id: $classroom->getId()->toRfc4122(),
                shortRef: AdminShortRef::fromUuid($classroom->getId()),
                name: $classroom->getName(),
                sectionCode: $classroom->getSectionCode(),
                gradeLabel: self::gradeLabel($classroom),
                status: $classroom->getStatus(),
                capacity: $classroom->getCapacity(),
                updatedAt: $classroom->getUpdatedAt()->getTimestamp(),
                institutionId: $institution->getId()->toRfc4122(),
                institutionName: $institution->getName(),
                academicYearId: $year->getId()->toRfc4122(),
                academicYearName: $year->getName(),
                teachers: $teachers,
                activeTeacherCount: \count($teachers),
                endedTeacherCount: $this->endedTeacherCount($classroom),
                activeStudentCount: $students['active'],
                endedStudentCount: $students['ended'],
                canRevise: $canManage && $classroomActive && $institutionActive && AcademicYearStatus::Closed !== $year->getStatus(),
                canArchive: $canManage && $classroomActive && $institutionActive,
            );
        });
    }

    /**
     * @return list<AdminClassroomInstitutionChoice>
     */
    public function institutionChoices(Uuid $actorId): array
    {
        $this->actorGuard->requireClassroomsView($actorId);

        /** @var list<array{id: Uuid|string, name: string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('i.id AS id, i.name AS name')
            ->from(Institution::class, 'i')
            ->orderBy('i.name', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $choices = [];
        foreach ($rows as $row) {
            $id = $row['id'] instanceof Uuid ? $row['id']->toRfc4122() : (string) $row['id'];
            $choices[] = new AdminClassroomInstitutionChoice($id, $row['name']);
        }

        return $choices;
    }

    /**
     * @return list<AdminClassroomYearChoice>
     */
    public function yearChoices(Uuid $actorId, bool $creatableOnly = false): array
    {
        $this->actorGuard->requireClassroomsView($actorId);
        $qb = $this->em->createQueryBuilder()
            ->select('y', 'i')
            ->from(AcademicYear::class, 'y')
            ->join('y.institution', 'i')
            ->orderBy('i.name', 'ASC')
            ->addOrderBy('y.startsAt', 'DESC')
            ->addOrderBy('y.id', 'ASC');
        if ($creatableOnly) {
            $qb->andWhere('i.status = :institutionStatus')
                ->andWhere('y.status != :closed')
                ->setParameter('institutionStatus', InstitutionStatus::Active)
                ->setParameter('closed', AcademicYearStatus::Closed);
        }

        /** @var list<AcademicYear> $years */
        $years = $qb->getQuery()->getResult();
        $choices = [];
        foreach ($years as $year) {
            $choices[] = new AdminClassroomYearChoice(
                $year->getId()->toRfc4122(),
                $year->getInstitution()->getId()->toRfc4122(),
                $year->getInstitution()->getName().' — '.$year->getName(),
            );
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $rawFilters
     *
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $rawFilters): array
    {
        $filters = [];
        foreach (self::ALLOWED_FILTER_KEYS as $key) {
            if (\array_key_exists($key, $rawFilters)) {
                $filters[$key] = $rawFilters[$key];
            }
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['q']) && \is_string($filters['q']) && '' !== $filters['q']) {
            try {
                $term = AdminLikeEscape::normalizeSearch($filters['q']);
            } catch (\InvalidArgumentException) {
                throw CommerceException::invalidInput('Search query is too long.');
            }
            if (null !== $term) {
                $pattern = AdminLikeEscape::containsPattern($term);
                $qb->andWhere("(c.name LIKE :q ESCAPE '!' OR c.sectionCode LIKE :q ESCAPE '!')")
                    ->setParameter('q', $pattern);
            }
        }

        if (isset($filters['institution']) && \is_string($filters['institution']) && '' !== $filters['institution']) {
            if (!Uuid::isValid($filters['institution'])) {
                throw CommerceException::invalidInput('Invalid institution filter.');
            }
            $qb->andWhere('IDENTITY(c.institution) = :institution')
                ->setParameter('institution', Uuid::fromString($filters['institution']), 'uuid');
        }

        if (isset($filters['year']) && \is_string($filters['year']) && '' !== $filters['year']) {
            if (!Uuid::isValid($filters['year'])) {
                throw CommerceException::invalidInput('Invalid academic year filter.');
            }
            $qb->andWhere('IDENTITY(c.academicYear) = :year')
                ->setParameter('year', Uuid::fromString($filters['year']), 'uuid');
        }

        if (isset($filters['status']) && \is_string($filters['status']) && '' !== $filters['status']) {
            $status = ClassroomStatus::tryFrom($filters['status']);
            if (!$status instanceof ClassroomStatus) {
                throw CommerceException::invalidInput('Invalid classroom status filter.');
            }
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }
    }

    /**
     * @param list<Classroom> $classrooms
     *
     * @return array<string, list<AdminClassroomTeacherView>>
     */
    private function activeTeachers(array $classrooms): array
    {
        if ([] === $classrooms) {
            return [];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('a', 'm', 'u')
            ->from(ClassroomTeacherAssignment::class, 'a')
            ->join('a.teacherMembership', 'm')
            ->join('m.user', 'u')
            ->andWhere('a.status = :assigned')
            ->setParameter('assigned', TeacherAssignmentStatus::Active)
            ->addOrderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC');
        $match = $qb->expr()->orX();
        foreach (array_values($classrooms) as $index => $classroom) {
            $match->add('IDENTITY(a.classroom) = :classroom'.$index);
            $qb->setParameter('classroom'.$index, $classroom->getId(), 'uuid');
        }
        /** @var list<ClassroomTeacherAssignment> $assignments */
        $assignments = $qb
            ->andWhere($match)
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($assignments as $assignment) {
            $user = $assignment->getTeacherMembership()->getUser();
            $name = trim($user->getFirstName().' '.$user->getLastName());
            $role = $assignment->getRole();
            $grouped[$assignment->getClassroom()->getId()->toRfc4122()][] = new AdminClassroomTeacherView(
                '' !== $name ? $name : 'Öğretmen',
                self::roleLabel($role),
                TeacherAssignmentRole::HomeroomTeacher === $role,
            );
        }

        return $grouped;
    }

    /**
     * @param list<AdminClassroomTeacherView> $teachers
     */
    private static function teacherSummary(array $teachers): string
    {
        if ([] === $teachers) {
            return 'Öğretmen atanmamış';
        }
        $primary = $teachers[0];
        foreach ($teachers as $teacher) {
            if ($teacher->homeroom) {
                $primary = $teacher;
                break;
            }
        }
        $summary = $primary->name.' · '.$primary->roleLabel;
        $extra = \count($teachers) - 1;
        if ($extra > 0) {
            $summary .= ' ve '.$extra.' öğretmen daha';
        }

        return $summary;
    }

    private function endedTeacherCount(Classroom $classroom): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(ClassroomTeacherAssignment::class, 'a')
            ->andWhere('a.classroom = :classroom')
            ->andWhere('a.status = :ended')
            ->setParameter('classroom', $classroom)
            ->setParameter('ended', TeacherAssignmentStatus::Ended)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array{active: int, ended: int}
     */
    private function enrollmentCounts(Classroom $classroom): array
    {
        /** @var list<array{status: StudentEnrollmentStatus|string, cnt: int|string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('e.status AS status, COUNT(e.id) AS cnt')
            ->from(ClassroomStudentEnrollment::class, 'e')
            ->andWhere('e.classroom = :classroom')
            ->setParameter('classroom', $classroom)
            ->groupBy('e.status')
            ->getQuery()
            ->getArrayResult();

        $counts = ['active' => 0, 'ended' => 0];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof StudentEnrollmentStatus ? $row['status']->value : (string) $row['status'];
            if (StudentEnrollmentStatus::Active->value === $status) {
                $counts['active'] = (int) $row['cnt'];
            }
            if (StudentEnrollmentStatus::Ended->value === $status) {
                $counts['ended'] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    private static function gradeLabel(Classroom $classroom): string
    {
        return $classroom->getGradeLevel()->value.'. sınıf';
    }

    private static function roleLabel(TeacherAssignmentRole $role): string
    {
        return match ($role) {
            TeacherAssignmentRole::HomeroomTeacher => 'Sınıf öğretmeni',
            TeacherAssignmentRole::AssistantTeacher => 'Yardımcı öğretmen',
        };
    }
}

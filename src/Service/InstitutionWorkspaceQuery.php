<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\InstitutionClassroomRow;
use App\Dto\InstitutionPersonRow;
use App\Dto\InstitutionTestRow;
use App\Dto\InstitutionWorkspaceOverview;
use App\Entity\Assessment;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentItem;
use App\Entity\Classroom;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\StudentProfile;
use App\Entity\User;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\ClassroomStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionType;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentStatus;
use App\Repository\InstitutionApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Read models for one already-authorized institution. Every query filters that id in SQL.
 */
final class InstitutionWorkspaceQuery
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly InstitutionApplicationRepository $applications,
    ) {
    }

    public function overview(InstitutionMembership $membership, bool $canSwitch): InstitutionWorkspaceOverview
    {
        $institution = $membership->getInstitution();
        $createdAt = $institution->getCreatedAt()->setTimezone(new \DateTimeZone('Europe/Istanbul'));

        return new InstitutionWorkspaceOverview(
            $institution->getName(),
            self::typeLabel($institution->getType()),
            self::institutionStatusLabel($institution->getStatus()->value),
            InstitutionWorkspaceGate::roleLabel($membership->getRole()->value),
            $createdAt->format('d.m.Y'),
            $canSwitch,
            $this->countRole($institution, InstitutionMembershipRole::Teacher),
            $this->countRole($institution, InstitutionMembershipRole::Student),
            $this->countWhere(Classroom::class, $institution),
            $this->countPublishedTests($institution),
            $this->countActiveDeliveries($institution),
        );
    }

    /**
     * @return list<InstitutionClassroomRow>
     */
    public function classrooms(Institution $institution, int $page): array
    {
        /** @var list<Classroom> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c', 'y')
            ->from(Classroom::class, 'c')
            ->innerJoin('c.academicYear', 'y')
            ->andWhere('c.institution = :institution')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->orderBy('c.name', 'ASC')
            ->setFirstResult(max(0, $page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();
        $teacherCounts = $this->countsByClassroom(ClassroomTeacherAssignment::class, $institution, TeacherAssignmentStatus::Active);
        $studentCounts = $this->countsByClassroom(ClassroomStudentEnrollment::class, $institution, StudentEnrollmentStatus::Active);
        $list = [];
        foreach ($rows as $classroom) {
            $key = $classroom->getId()->toRfc4122();
            $list[] = new InstitutionClassroomRow(
                $this->hasher->workspaceReference('classroom', $classroom->getId()),
                $classroom->getName(),
                $classroom->getGradeLevel()->value.'. sınıf',
                $classroom->getAcademicYear()->getName(),
                ClassroomStatus::Active === $classroom->getStatus() ? 'Aktif' : 'Arşiv',
                $teacherCounts[$key] ?? 0,
                $studentCounts[$key] ?? 0,
            );
        }

        return $list;
    }

    public function classroom(Institution $institution, string $reference): ?InstitutionClassroomRow
    {
        /** @var list<array{id: mixed}> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('c.id AS id')
            ->from(Classroom::class, 'c')
            ->andWhere('c.institution = :institution')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();
        foreach ($ids as $row) {
            $id = self::uuid($row['id']);
            if ($id instanceof Uuid && hash_equals($this->hasher->workspaceReference('classroom', $id), $reference)) {
                return $this->classroomRow($institution, $id);
            }
        }

        return null;
    }

    /**
     * @return list<InstitutionPersonRow>
     */
    public function teachers(Institution $institution, int $page): array
    {
        /** @var list<array{membershipId: mixed, firstName: string, lastName: string, role: InstitutionMembershipRole, status: InstitutionMembershipStatus}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('m.id AS membershipId', 'u.firstName AS firstName', 'u.lastName AS lastName', 'm.role AS role', 'm.status AS status')
            ->from(InstitutionMembership::class, 'm')
            ->innerJoin('m.user', 'u')
            ->andWhere('m.institution = :institution')
            ->andWhere('m.role = :role')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('role', InstitutionMembershipRole::Teacher)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->setFirstResult(max(0, $page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getArrayResult();
        $assigned = $this->assignedClassroomCounts($institution);
        $list = [];
        foreach ($rows as $row) {
            $key = self::idKey($row['membershipId']);
            $list[] = new InstitutionPersonRow(
                trim($row['firstName'].' '.$row['lastName']),
                InstitutionWorkspaceGate::roleLabel(self::scalarEnum($row['role'])),
                self::membershipStatusLabel(self::scalarEnum($row['status'])),
                null,
                null,
                $assigned[$key] ?? 0,
            );
        }

        return $list;
    }

    /**
     * @return list<InstitutionPersonRow>
     */
    public function students(Institution $institution, int $page): array
    {
        /** @var list<array{membershipId: mixed, firstName: string, lastName: string, status: InstitutionMembershipStatus, gradeLevel: int|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('m.id AS membershipId', 'u.firstName AS firstName', 'u.lastName AS lastName', 'm.status AS status', 'p.gradeLevel AS gradeLevel')
            ->from(InstitutionMembership::class, 'm')
            ->innerJoin('m.user', 'u')
            ->leftJoin(StudentProfile::class, 'p', 'WITH', 'p.user = u')
            ->andWhere('m.institution = :institution')
            ->andWhere('m.role = :role')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('role', InstitutionMembershipRole::Student)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->setFirstResult(max(0, $page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getArrayResult();
        $classrooms = $this->studentClassroomNames($institution);
        $list = [];
        foreach ($rows as $row) {
            $grade = self::scalarEnum($row['gradeLevel']);
            $list[] = new InstitutionPersonRow(
                trim($row['firstName'].' '.$row['lastName']),
                'Öğrenci',
                self::membershipStatusLabel(self::scalarEnum($row['status'])),
                '' === $grade ? null : $grade.'. sınıf',
                $classrooms[self::idKey($row['membershipId'])] ?? null,
                null,
            );
        }

        return $list;
    }

    /**
     * @return list<InstitutionTestRow>
     */
    public function tests(Institution $institution, int $page): array
    {
        /** @var list<Assessment> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('a', 'r')
            ->from(Assessment::class, 'a')
            ->leftJoin('a.publishedRevision', 'r')
            ->andWhere('a.institution = :institution')
            ->andWhere('a.scope = :scope')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('scope', AssessmentScope::Institution)
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(max(0, $page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();
        $items = $this->itemCounts($institution);
        $deliveries = $this->deliveryLabels($institution);
        $list = [];
        foreach ($rows as $assessment) {
            $revision = $assessment->getPublishedRevision() ?? $assessment->getCurrentRevision();
            $publishedAt = $assessment->getPublishedAt();
            $key = $assessment->getId()->toRfc4122();
            $list[] = new InstitutionTestRow(
                $this->hasher->workspaceReference('assessment', $assessment->getId()),
                $revision?->getTitle() ?? $assessment->getCode(),
                $assessment->getGradeLevel()->value.'. sınıf',
                self::assessmentStatusLabel($assessment->getStatus()->value),
                $items[$key] ?? 0,
                null === $publishedAt ? null : $publishedAt->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('d.m.Y'),
                $deliveries[$key] ?? null,
            );
        }

        return $list;
    }

    public function test(Institution $institution, string $reference): ?InstitutionTestRow
    {
        /** @var list<array{id: mixed}> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('a.id AS id')
            ->from(Assessment::class, 'a')
            ->andWhere('a.institution = :institution')
            ->andWhere('a.scope = :scope')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('scope', AssessmentScope::Institution)
            ->getQuery()
            ->getArrayResult();
        foreach ($ids as $row) {
            $id = self::uuid($row['id']);
            if ($id instanceof Uuid && hash_equals($this->hasher->workspaceReference('assessment', $id), $reference)) {
                foreach ($this->tests($institution, 1) as $test) {
                    if (hash_equals($test->reference, $reference)) {
                        return $test;
                    }
                }
            }
        }

        return null;
    }

    public function applicationMessage(User $user): ?string
    {
        $application = $this->applications->findLatestForUser($user);
        if (null === $application) {
            return null;
        }

        return match ($application->getStatus()) {
            OnboardingApplicationStatus::Pending => 'Başvurunuz inceleniyor.',
            OnboardingApplicationStatus::Rejected => 'Başvurunuz reddedildi.',
            OnboardingApplicationStatus::Approved => 'Başvurunuz onaylandı. Kurum erişimi üyelik açıldığında görünür.',
            OnboardingApplicationStatus::Withdrawn => 'Başvurunuz geri çekildi.',
            OnboardingApplicationStatus::Superseded => 'Bu başvurunun yerine yenisi alınmış.',
        };
    }

    private function countRole(Institution $institution, InstitutionMembershipRole $role): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(InstitutionMembership::class, 'm')
            ->andWhere('m.institution = :institution')
            ->andWhere('m.role = :role')
            ->andWhere('m.status = :status')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('role', $role)
            ->setParameter('status', InstitutionMembershipStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param class-string $class
     */
    private function countWhere(string $class, Institution $institution): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(row.id)')
            ->from($class, 'row')
            ->andWhere('row.institution = :institution')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countPublishedTests(Institution $institution): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Assessment::class, 'a')
            ->andWhere('a.institution = :institution')
            ->andWhere('a.scope = :scope')
            ->andWhere('a.status = :status')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('scope', AssessmentScope::Institution)
            ->setParameter('status', AssessmentStatus::Published)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countActiveDeliveries(Institution $institution): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(AssessmentDelivery::class, 'd')
            ->andWhere('d.institution = :institution')
            ->andWhere('d.status = :status')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('status', AssessmentDeliveryStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param class-string $class
     *
     * @return array<string, int>
     */
    private function countsByClassroom(string $class, Institution $institution, \BackedEnum $status): array
    {
        /** @var list<array{classroomId: mixed, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(row.classroom) AS classroomId', 'COUNT(row.id) AS total')
            ->from($class, 'row')
            ->andWhere('row.institution = :institution')
            ->andWhere('row.status = :status')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('status', $status)
            ->groupBy('row.classroom')
            ->getQuery()
            ->getArrayResult();
        $map = [];
        foreach ($rows as $row) {
            $map[self::idKey($row['classroomId'])] = (int) $row['total'];
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function assignedClassroomCounts(Institution $institution): array
    {
        /** @var list<array{membershipId: mixed, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(a.teacherMembership) AS membershipId', 'COUNT(a.id) AS total')
            ->from(ClassroomTeacherAssignment::class, 'a')
            ->andWhere('a.institution = :institution')
            ->andWhere('a.status = :status')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('status', TeacherAssignmentStatus::Active)
            ->groupBy('a.teacherMembership')
            ->getQuery()
            ->getArrayResult();
        $map = [];
        foreach ($rows as $row) {
            $map[self::idKey($row['membershipId'])] = (int) $row['total'];
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function studentClassroomNames(Institution $institution): array
    {
        /** @var list<array{membershipId: mixed, classroomName: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(e.studentMembership) AS membershipId', 'c.name AS classroomName')
            ->from(ClassroomStudentEnrollment::class, 'e')
            ->innerJoin('e.classroom', 'c')
            ->andWhere('e.institution = :institution')
            ->andWhere('e.status = :status')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('status', StudentEnrollmentStatus::Active)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getArrayResult();
        $map = [];
        foreach ($rows as $row) {
            $key = self::idKey($row['membershipId']);
            if (!isset($map[$key])) {
                $map[$key] = $row['classroomName'];
            }
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function itemCounts(Institution $institution): array
    {
        /** @var list<array{assessmentId: mixed, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(rev.assessment) AS assessmentId', 'COUNT(i.id) AS total')
            ->from(AssessmentItem::class, 'i')
            ->innerJoin('i.assessmentRevision', 'rev')
            ->innerJoin('rev.assessment', 'a')
            ->andWhere('a.institution = :institution')
            ->andWhere('a.scope = :scope')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('scope', AssessmentScope::Institution)
            ->groupBy('rev.assessment')
            ->getQuery()
            ->getArrayResult();
        $map = [];
        foreach ($rows as $row) {
            $map[self::idKey($row['assessmentId'])] = (int) $row['total'];
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function deliveryLabels(Institution $institution): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(d.assessment) AS assessmentId', 'd.status AS status')
            ->from(AssessmentDelivery::class, 'd')
            ->andWhere('d.institution = :institution')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();
        $map = [];
        foreach ($rows as $row) {
            $key = self::idKey($row['assessmentId']);
            $statusValue = $row['status'];
            $status = $statusValue instanceof AssessmentDeliveryStatus
                ? $statusValue
                : AssessmentDeliveryStatus::tryFrom(\is_string($statusValue) ? $statusValue : '');
            if (!$status instanceof AssessmentDeliveryStatus) {
                continue;
            }
            $label = match ($status) {
                AssessmentDeliveryStatus::Active => 'Devam ediyor',
                AssessmentDeliveryStatus::Draft => 'Taslak',
                AssessmentDeliveryStatus::Closed => 'Kapandı',
                AssessmentDeliveryStatus::Cancelled => 'İptal',
            };
            if (!isset($map[$key]) || 'Devam ediyor' === $label) {
                $map[$key] = $label;
            }
        }

        return $map;
    }

    private function classroomRow(Institution $institution, Uuid $id): ?InstitutionClassroomRow
    {
        foreach ($this->classrooms($institution, 1) as $row) {
            if (hash_equals($row->reference, $this->hasher->workspaceReference('classroom', $id))) {
                return $row;
            }
        }

        return null;
    }

    private static function uuid(mixed $value): ?Uuid
    {
        if ($value instanceof Uuid) {
            return $value;
        }
        if (\is_string($value) && Uuid::isValid($value)) {
            return Uuid::fromString($value);
        }

        return null;
    }

    private static function scalarEnum(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if (\is_int($value) || \is_string($value)) {
            return (string) $value;
        }

        return '';
    }

    private static function idKey(mixed $value): string
    {
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }
        if (\is_string($value)) {
            return strtolower($value);
        }

        return '';
    }

    private static function typeLabel(InstitutionType $type): string
    {
        return match ($type) {
            InstitutionType::School => 'Okul',
            InstitutionType::CourseCenter => 'Kurs',
            InstitutionType::TutoringCenter => 'Etüt merkezi',
            InstitutionType::Other => 'Diğer',
        };
    }

    private static function institutionStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Aktif',
            'pending' => 'Beklemede',
            'suspended' => 'Askıda',
            'archived' => 'Arşiv',
            default => 'Belirsiz',
        };
    }

    private static function membershipStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Aktif',
            'pending' => 'Beklemede',
            'suspended' => 'Askıda',
            'ended' => 'Sona erdi',
            default => 'Belirsiz',
        };
    }

    private static function assessmentStatusLabel(string $status): string
    {
        return match ($status) {
            'published' => 'Yayında',
            'draft' => 'Taslak',
            'in_review' => 'İncelemede',
            'archived' => 'Arşiv',
            default => 'Belirsiz',
        };
    }
}

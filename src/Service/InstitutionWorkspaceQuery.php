<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\InstitutionAcademicYearRow;
use App\Dto\InstitutionAccountMembership;
use App\Dto\InstitutionAssignedTeacher;
use App\Dto\InstitutionClassroomDetail;
use App\Dto\InstitutionClassroomRow;
use App\Dto\InstitutionEnrolledStudent;
use App\Dto\InstitutionPersonRow;
use App\Dto\InstitutionStudentInviteRow;
use App\Dto\InstitutionTeacherInviteRow;
use App\Dto\InstitutionTestRow;
use App\Dto\InstitutionTransferTarget;
use App\Dto\InstitutionWorkspaceOverview;
use App\Entity\AcademicYear;
use App\Entity\Assessment;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentItem;
use App\Entity\Classroom;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\InstitutionStudentInvitation;
use App\Entity\InstitutionTeacherInvitation;
use App\Entity\StudentProfile;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\ClassroomStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionType;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentRole;
use App\Enum\TeacherAssignmentStatus;
use App\Repository\InstitutionApplicationRepository;
use Doctrine\DBAL\Types\Types;
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
    public function classrooms(Institution $institution, int $page, ClassroomStatus $status = ClassroomStatus::Active): array
    {
        /** @var list<Classroom> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c', 'y')
            ->from(Classroom::class, 'c')
            ->innerJoin('c.academicYear', 'y')
            ->andWhere('c.institution = :institution')
            ->andWhere('c.status = :status')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('status', $status)
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
            $list[] = $this->mapClassroom($classroom, $teacherCounts[$key] ?? 0, $studentCounts[$key] ?? 0);
        }

        return $list;
    }

    public function classroom(Institution $institution, string $reference): ?InstitutionClassroomDetail
    {
        $classroom = $this->classroomEntity($institution, $reference);
        if (!$classroom instanceof Classroom) {
            return null;
        }
        $key = $classroom->getId()->toRfc4122();
        $teacherCounts = $this->countsByClassroom(ClassroomTeacherAssignment::class, $institution, TeacherAssignmentStatus::Active);
        $studentCounts = $this->countsByClassroom(ClassroomStudentEnrollment::class, $institution, StudentEnrollmentStatus::Active);

        return new InstitutionClassroomDetail(
            $this->mapClassroom($classroom, $teacherCounts[$key] ?? 0, $studentCounts[$key] ?? 0),
            $this->classroomTeachers($institution, $classroom),
            $this->classroomStudents($institution, $classroom),
            $this->studentInvites($classroom),
        );
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
            ->andWhere('m.status = :memberStatus')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('role', InstitutionMembershipRole::Teacher)
            ->setParameter('memberStatus', InstitutionMembershipStatus::Active)
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
     * @return list<InstitutionTeacherInviteRow>
     */
    public function teacherInvites(Institution $institution, bool $history): array
    {
        $now = new \DateTimeImmutable();
        $qb = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(InstitutionTeacherInvitation::class, 'i')
            ->andWhere('i.institution = :institution')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults(self::PAGE_SIZE);
        if ($history) {
            $qb->andWhere('i.consumedAt IS NOT NULL OR i.revokedAt IS NOT NULL OR i.expiresAt <= :now');
        } else {
            $qb->andWhere('i.consumedAt IS NULL AND i.revokedAt IS NULL AND i.expiresAt > :now');
        }
        /** @var list<InstitutionTeacherInvitation> $rows */
        $rows = $qb->getQuery()->getResult();
        $zone = new \DateTimeZone('Europe/Istanbul');
        $list = [];
        foreach ($rows as $invitation) {
            $usable = $invitation->isUsable($now);
            $status = 'Süresi doldu';
            if ($invitation->isConsumed()) {
                $status = 'Kabul edildi';
            } elseif ($invitation->isRevoked()) {
                $status = 'İptal edildi';
            } elseif ($usable) {
                $status = 'Bekliyor';
            }
            $list[] = new InstitutionTeacherInviteRow(
                $this->hasher->workspaceReference('teacher_invite', $invitation->getId()),
                self::maskEmail($invitation->getNormalizedEmail()),
                $status,
                $invitation->getCreatedAt()->setTimezone($zone)->format('d.m.Y H:i'),
                $invitation->getExpiresAt()->setTimezone($zone)->format('d.m.Y H:i'),
                $invitation->getOperatorNote(),
                $usable,
                $usable,
            );
        }

        return $list;
    }

    /**
     * @return list<InstitutionAccountMembership>
     */
    public function accountMemberships(User $user): array
    {
        /** @var list<InstitutionMembership> $memberships */
        $memberships = $this->entityManager->createQueryBuilder()
            ->select('m', 'i')
            ->from(InstitutionMembership::class, 'm')
            ->innerJoin('m.institution', 'i')
            ->andWhere('m.user = :user')
            ->andWhere('m.status = :status')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('status', InstitutionMembershipStatus::Active)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
        $list = [];
        foreach ($memberships as $membership) {
            $names = [];
            if (InstitutionMembershipRole::Teacher === $membership->getRole()) {
                /** @var list<array{name: string}> $rooms */
                $rooms = $this->entityManager->createQueryBuilder()
                    ->select('c.name AS name')
                    ->from(ClassroomTeacherAssignment::class, 'a')
                    ->innerJoin('a.classroom', 'c')
                    ->andWhere('a.teacherMembership = :membership')
                    ->andWhere('a.institution = :institution')
                    ->andWhere('a.status = :status')
                    ->setParameter('membership', $membership->getId(), 'uuid')
                    ->setParameter('institution', $membership->getInstitution()->getId(), 'uuid')
                    ->setParameter('status', TeacherAssignmentStatus::Active)
                    ->orderBy('c.name', 'ASC')
                    ->getQuery()
                    ->getArrayResult();
                foreach ($rooms as $room) {
                    $names[] = $room['name'];
                }
            }
            if (InstitutionMembershipRole::Student === $membership->getRole()) {
                /** @var list<array{name: string}> $rooms */
                $rooms = $this->entityManager->createQueryBuilder()
                    ->select('c.name AS name')
                    ->from(ClassroomStudentEnrollment::class, 'e')
                    ->innerJoin('e.classroom', 'c')
                    ->andWhere('e.studentMembership = :membership')
                    ->andWhere('e.institution = :institution')
                    ->andWhere('e.status = :status')
                    ->setParameter('membership', $membership->getId(), 'uuid')
                    ->setParameter('institution', $membership->getInstitution()->getId(), 'uuid')
                    ->setParameter('status', StudentEnrollmentStatus::Active)
                    ->orderBy('c.name', 'ASC')
                    ->getQuery()
                    ->getArrayResult();
                foreach ($rooms as $room) {
                    $names[] = $room['name'];
                }
            }
            $list[] = new InstitutionAccountMembership(
                $membership->getInstitution()->getName(),
                InstitutionWorkspaceGate::roleLabel($membership->getRole()->value),
                self::membershipStatusLabel($membership->getStatus()->value),
                $names,
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

    /**
     * @return list<InstitutionAcademicYearRow>
     */
    public function academicYears(Institution $institution): array
    {
        /** @var list<AcademicYear> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('y')
            ->from(AcademicYear::class, 'y')
            ->andWhere('y.institution = :institution')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->orderBy('y.startsAt', 'DESC')
            ->addOrderBy('y.name', 'ASC')
            ->getQuery()
            ->getResult();
        $list = [];
        foreach ($rows as $year) {
            $list[] = new InstitutionAcademicYearRow(
                $this->hasher->workspaceReference('academic_year', $year->getId()),
                $year->getName(),
                self::academicYearStatusLabel($year->getStatus()),
                $year->getStartsAt()->format('d.m.Y'),
                $year->getEndsAt()->format('d.m.Y'),
                AcademicYearStatus::Planned === $year->getStatus(),
            );
        }

        return $list;
    }

    public function academicYear(Institution $institution, string $reference): ?AcademicYear
    {
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return null;
        }

        /** @var list<array{id: mixed}> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('y.id AS id')
            ->from(AcademicYear::class, 'y')
            ->andWhere('y.institution = :institution')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();
        foreach ($ids as $row) {
            $id = self::uuid($row['id']);
            if (!$id instanceof Uuid || !hash_equals($this->hasher->workspaceReference('academic_year', $id), $reference)) {
                continue;
            }

            $year = $this->entityManager->find(AcademicYear::class, $id);

            return $year instanceof AcademicYear && $year->getInstitution()->getId()->equals($institution->getId())
                ? $year
                : null;
        }

        return null;
    }

    private function classroomEntity(Institution $institution, string $reference): ?Classroom
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
            if (!$id instanceof Uuid || !hash_equals($this->hasher->workspaceReference('classroom', $id), $reference)) {
                continue;
            }

            $classroom = $this->entityManager->createQueryBuilder()
                ->select('c', 'y')
                ->from(Classroom::class, 'c')
                ->innerJoin('c.academicYear', 'y')
                ->andWhere('c.id = :id')
                ->andWhere('c.institution = :institution')
                ->setParameter('id', $id, 'uuid')
                ->setParameter('institution', $institution->getId(), 'uuid')
                ->getQuery()
                ->getOneOrNullResult();

            return $classroom instanceof Classroom ? $classroom : null;
        }

        return null;
    }

    private function mapClassroom(Classroom $classroom, int $teacherCount, int $studentCount): InstitutionClassroomRow
    {
        $zone = new \DateTimeZone('Europe/Istanbul');
        $capacity = $classroom->getCapacity();

        return new InstitutionClassroomRow(
            $this->hasher->workspaceReference('classroom', $classroom->getId()),
            $classroom->getName(),
            $classroom->getGradeLevel()->value.'. sınıf',
            $classroom->getAcademicYear()->getName(),
            $classroom->getSectionCode(),
            null === $capacity ? null : (string) $capacity,
            ClassroomStatus::Active === $classroom->getStatus() ? 'Aktif' : 'Arşiv',
            $classroom->getCreatedAt()->setTimezone($zone)->format('d.m.Y'),
            $classroom->getUpdatedAt()->setTimezone($zone)->format('d.m.Y'),
            (string) $classroom->getUpdatedAt()->getTimestamp(),
            ClassroomStatus::Active === $classroom->getStatus(),
            $teacherCount,
            $studentCount,
        );
    }

    /**
     * @return list<InstitutionAssignedTeacher>
     */
    private function classroomTeachers(Institution $institution, Classroom $classroom): array
    {
        /** @var list<array{assignmentId: mixed, firstName: string, lastName: string, role: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('a.id AS assignmentId', 'u.firstName AS firstName', 'u.lastName AS lastName', 'a.role AS role')
            ->from(ClassroomTeacherAssignment::class, 'a')
            ->innerJoin('a.teacherMembership', 'm')
            ->innerJoin('m.user', 'u')
            ->andWhere('a.institution = :institution')
            ->andWhere('a.classroom = :classroom')
            ->andWhere('a.status = :status')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->setParameter('status', TeacherAssignmentStatus::Active)
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getArrayResult();
        $list = [];
        foreach ($rows as $row) {
            $id = $row['assignmentId'] instanceof Uuid
                ? $row['assignmentId']
                : (\is_string($row['assignmentId']) && Uuid::isValid($row['assignmentId']) ? Uuid::fromString($row['assignmentId']) : null);
            if (!$id instanceof Uuid) {
                continue;
            }
            $roleValue = $row['role'] instanceof TeacherAssignmentRole ? $row['role']->value : (\is_string($row['role']) ? $row['role'] : '');
            $role = TeacherAssignmentRole::tryFrom($roleValue);
            $list[] = new InstitutionAssignedTeacher(
                trim($row['firstName'].' '.$row['lastName']),
                TeacherAssignmentRole::HomeroomTeacher === $role ? 'Sınıf öğretmeni' : 'Yardımcı öğretmen',
                $this->hasher->workspaceReference('teacher_assignment', $id),
            );
        }

        return $list;
    }

    /**
     * @return list<InstitutionEnrolledStudent>
     */
    private function classroomStudents(Institution $institution, Classroom $classroom): array
    {
        /** @var list<array{enrollmentId: mixed, firstName: string, lastName: string, gradeLevel: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.id AS enrollmentId', 'u.firstName AS firstName', 'u.lastName AS lastName', 'p.gradeLevel AS gradeLevel')
            ->from(ClassroomStudentEnrollment::class, 'e')
            ->innerJoin('e.studentMembership', 'm')
            ->innerJoin('m.user', 'u')
            ->leftJoin(StudentProfile::class, 'p', 'WITH', 'p.user = u')
            ->andWhere('e.institution = :institution')
            ->andWhere('e.classroom = :classroom')
            ->andWhere('e.status = :status')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->setParameter('status', StudentEnrollmentStatus::Active)
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getArrayResult();
        $targets = $this->transferTargets($institution, $classroom);
        $list = [];
        foreach ($rows as $row) {
            $id = self::uuid($row['enrollmentId']);
            if (!$id instanceof Uuid) {
                continue;
            }
            $grade = self::scalarEnum($row['gradeLevel']);
            $gradeValue = '' === $grade ? null : (int) $grade;
            $eligible = [];
            foreach ($targets as $target) {
                if ($target['grade'] === $gradeValue && $target['open']) {
                    $eligible[] = new InstitutionTransferTarget($target['reference'], $target['name']);
                }
            }
            $list[] = new InstitutionEnrolledStudent(
                trim($row['firstName'].' '.$row['lastName']),
                null === $gradeValue ? null : $gradeValue.'. sınıf',
                $this->hasher->workspaceReference('student_enrollment', $id),
                $eligible,
            );
        }

        return $list;
    }

    /**
     * @return list<InstitutionStudentInviteRow>
     */
    private function studentInvites(Classroom $classroom): array
    {
        $now = new \DateTimeImmutable();
        /** @var list<InstitutionStudentInvitation> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(InstitutionStudentInvitation::class, 'i')
            ->andWhere('i.classroom = :classroom')
            ->andWhere('i.consumedAt IS NULL')
            ->andWhere('i.revokedAt IS NULL')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();
        $zone = new \DateTimeZone('Europe/Istanbul');
        $list = [];
        foreach ($rows as $invitation) {
            $list[] = new InstitutionStudentInviteRow(
                $this->hasher->workspaceReference('student_invite', $invitation->getId()),
                self::maskEmail($invitation->getNormalizedEmail()),
                'Bekliyor',
                $invitation->getCreatedAt()->setTimezone($zone)->format('d.m.Y H:i'),
                $invitation->getExpiresAt()->setTimezone($zone)->format('d.m.Y H:i'),
                true,
                true,
            );
        }

        return $list;
    }

    /**
     * @return list<array{reference: string, name: string, grade: int, open: bool}>
     */
    private function transferTargets(Institution $institution, Classroom $source): array
    {
        /** @var list<Classroom> $rooms */
        $rooms = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Classroom::class, 'c')
            ->andWhere('c.institution = :institution')
            ->andWhere('c.academicYear = :year')
            ->andWhere('c.status = :status')
            ->andWhere('c.id != :source')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('year', $source->getAcademicYear()->getId(), 'uuid')
            ->setParameter('status', ClassroomStatus::Active)
            ->setParameter('source', $source->getId(), 'uuid')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
        $list = [];
        foreach ($rooms as $room) {
            $capacity = $room->getCapacity();
            $open = null === $capacity || $this->activeEnrollmentCount($room) < $capacity;
            $list[] = [
                'reference' => $this->hasher->workspaceReference('classroom', $room->getId()),
                'name' => $room->getName(),
                'grade' => $room->getGradeLevel()->value,
                'open' => $open,
            ];
        }

        return $list;
    }

    private function activeEnrollmentCount(Classroom $classroom): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(ClassroomStudentEnrollment::class, 'e')
            ->andWhere('e.classroom = :classroom')
            ->andWhere('e.status = :status')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->setParameter('status', StudentEnrollmentStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private static function academicYearStatusLabel(AcademicYearStatus $status): string
    {
        return match ($status) {
            AcademicYearStatus::Active => 'Aktif',
            AcademicYearStatus::Planned => 'Planlandı',
            AcademicYearStatus::Closed => 'Kapandı',
        };
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

    private static function maskEmail(string $normalized): string
    {
        $at = strpos($normalized, '@');
        if (false === $at || $at < 1) {
            return '***';
        }

        return substr($normalized, 0, 1).'***@'.substr($normalized, $at + 1);
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

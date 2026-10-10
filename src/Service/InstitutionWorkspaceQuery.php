<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\InstitutionAcademicYearRow;
use App\Dto\InstitutionAccountMembership;
use App\Dto\InstitutionAssignedTeacher;
use App\Dto\InstitutionClassroomDetail;
use App\Dto\InstitutionClassroomRow;
use App\Dto\InstitutionEnrolledStudent;
use App\Dto\InstitutionOutcomeChoice;
use App\Dto\InstitutionPersonRow;
use App\Dto\InstitutionQuestionOption;
use App\Dto\InstitutionQuestionResolution;
use App\Dto\InstitutionQuestionSelection;
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
use App\Entity\CurriculumLearningOutcome;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\InstitutionStudentInvitation;
use App\Entity\InstitutionTeacherInvitation;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\StudentProfile;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\ClassroomStatus;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionType;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\QuestionScope;
use App\Enum\QuestionStatus;
use App\Enum\QuestionType;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentRole;
use App\Enum\TeacherAssignmentStatus;
use App\Repository\InstitutionApplicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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

    /**
     * @return array{can_submit: bool, can_publish: bool}
     */
    public function testActions(Institution $institution, string $reference, User $actor): array
    {
        $assessment = $this->institutionAssessment($institution, $reference);
        $revision = $assessment?->getCurrentRevision();
        $author = $revision?->getCreatedBy();
        $isAuthor = $author instanceof User && $author->getId()->equals($actor->getId());

        return [
            'can_submit' => $assessment instanceof Assessment && AssessmentStatus::Draft === $assessment->getStatus(),
            'can_publish' => $assessment instanceof Assessment
                && AssessmentStatus::InReview === $assessment->getStatus()
                && !$isAuthor,
        ];
    }

    public function institutionAssessment(Institution $institution, string $reference): ?Assessment
    {
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return null;
        }
        $id = $this->matchingAssessmentId($institution, $reference);
        if (!$id instanceof Uuid) {
            return null;
        }
        $assessment = $this->entityManager->find(Assessment::class, $id);

        return $assessment instanceof Assessment
            && AssessmentScope::Institution === $assessment->getScope()
            && $assessment->getInstitution()?->getId()->equals($institution->getId())
            ? $assessment
            : null;
    }

    /**
     * @return list<int>
     */
    public function selectableGrades(Institution $institution): array
    {
        /** @var list<array{grade: mixed}> $rows */
        $rows = $this->eligibleQuestions($institution, null)
            ->select('DISTINCT q.gradeLevel AS grade')
            ->orderBy('q.gradeLevel', 'ASC')
            ->getQuery()
            ->getArrayResult();
        $grades = [];
        foreach ($rows as $row) {
            $grade = self::gradeValue($row['grade']);
            if (null !== $grade) {
                $grades[$grade] = $grade;
            }
        }
        $values = array_values($grades);
        sort($values);

        return $values;
    }

    /**
     * @return list<InstitutionQuestionOption>
     */
    public function selectableQuestions(Institution $institution, GradeLevel $grade): array
    {
        $options = [];
        foreach ($this->selectableCatalog($institution, $grade, true) as $row) {
            $options[] = new InstitutionQuestionOption(
                $row['reference'],
                $row['code'],
                $row['stem'],
                $row['subjectName'],
                $row['grade'],
            );
        }

        return $options;
    }

    /**
     * One catalog query for every posted reference.
     *
     * @param array<mixed> $references
     */
    public function resolveSelectableQuestions(Institution $institution, GradeLevel $grade, array $references): InstitutionQuestionResolution
    {
        $wanted = [];
        foreach ($references as $reference) {
            if (!\is_string($reference) || 1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
                return new InstitutionQuestionResolution(false, true, []);
            }
            $reference = strtolower($reference);
            if (isset($wanted[$reference])) {
                return new InstitutionQuestionResolution(false, true, []);
            }
            $wanted[$reference] = true;
        }
        if ([] === $wanted) {
            return new InstitutionQuestionResolution(false, false, []);
        }

        $catalog = [];
        foreach ($this->selectableCatalog($institution, $grade, false) as $row) {
            $catalog[$row['reference']] = $row;
        }
        if ([] === $catalog) {
            return new InstitutionQuestionResolution(true, true, []);
        }
        $items = [];
        foreach (array_keys($wanted) as $reference) {
            $row = $catalog[$reference] ?? null;
            if (null === $row) {
                return new InstitutionQuestionResolution(false, true, []);
            }
            $items[] = new InstitutionQuestionSelection($row['questionId'], $row['revisionId'], $row['subjectId']);
        }

        return new InstitutionQuestionResolution(false, false, $items);
    }

    /**
     * @return list<InstitutionOutcomeChoice>
     */
    public function publishedOutcomeChoices(Subject $subject, GradeLevel $grade): array
    {
        $choices = [];
        foreach ($this->publishedOutcomeRows($subject, $grade) as $row) {
            $choices[] = new InstitutionOutcomeChoice($row['reference'], $row['code'], $row['description']);
        }

        return $choices;
    }

    public function publishedOutcomeId(Subject $subject, GradeLevel $grade, string $reference): ?Uuid
    {
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return null;
        }
        $reference = strtolower($reference);
        foreach ($this->publishedOutcomeRows($subject, $grade) as $row) {
            if (hash_equals($row['reference'], $reference)) {
                return Uuid::fromString($row['id']);
            }
        }

        return null;
    }

    public function institutionQuestionId(Institution $institution, string $reference): ?Uuid
    {
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return null;
        }
        $reference = strtolower($reference);
        /** @var list<array{id: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('q.id AS id')
            ->from(Question::class, 'q')
            ->andWhere('q.scope = :scope')
            ->andWhere('q.institution = :institution')
            ->setParameter('scope', QuestionScope::Institution)
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $id = self::uuid($row['id']);
            if ($id instanceof Uuid && hash_equals($this->hasher->workspaceReference('question', $id), $reference)) {
                return $id;
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

    private function matchingAssessmentId(Institution $institution, string $reference): ?Uuid
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
                return $id;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, reference: string, code: string, description: string}>
     */
    private function publishedOutcomeRows(Subject $subject, GradeLevel $grade): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('o.id AS outcomeId, o.code AS code, o.description AS description')
            ->from(CurriculumLearningOutcome::class, 'o')
            ->innerJoin('o.curriculumProgram', 'p')
            ->innerJoin('o.topic', 't')
            ->andWhere('p.subject = :subject')
            ->andWhere('p.gradeLevel = :grade')
            ->andWhere('p.status = :published')
            ->andWhere('o.status = :active')
            ->andWhere('t.status = :active')
            ->setParameter('subject', $subject->getId(), 'uuid')
            ->setParameter('grade', $grade)
            ->setParameter('published', CurriculumStatus::Published)
            ->setParameter('active', CurriculumContentStatus::Active)
            ->orderBy('o.code', 'ASC')
            ->getQuery()
            ->getArrayResult();
        $catalog = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $outcomeId = self::uuid($row['outcomeId'] ?? null);
            $code = $row['code'] ?? null;
            $description = $row['description'] ?? null;
            if (!$outcomeId instanceof Uuid || !\is_string($code) || !\is_string($description)) {
                continue;
            }
            $catalog[] = [
                'id' => $outcomeId->toRfc4122(),
                'reference' => $this->hasher->workspaceReference('learning_outcome', $outcomeId),
                'code' => $code,
                'description' => mb_substr(trim($description), 0, 160),
            ];
        }

        return $catalog;
    }

    private function eligibleQuestions(Institution $institution, ?GradeLevel $grade): QueryBuilder
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->from(Question::class, 'q')
            ->innerJoin('q.subject', 's')
            ->innerJoin(
                QuestionRevision::class,
                'r',
                'WITH',
                'r.question = q AND r.revisionNumber = q.currentRevisionNumber',
            )
            ->andWhere('q.scope = :scope')
            ->andWhere('q.institution = :institution')
            ->andWhere('q.status = :status')
            ->andWhere('r.type = :type')
            ->setParameter('scope', QuestionScope::Institution)
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('status', QuestionStatus::Published)
            ->setParameter('type', QuestionType::SingleChoice);
        if ($grade instanceof GradeLevel) {
            $builder->andWhere('q.gradeLevel = :grade')->setParameter('grade', $grade);
        }

        return $builder;
    }

    /**
     * @return list<array{reference: string, questionId: string, revisionId: string, code: string, grade: int, subjectId: string, subjectName: string, stem: string}>
     */
    private function selectableCatalog(Institution $institution, GradeLevel $grade, bool $includeStem): array
    {
        $builder = $this->eligibleQuestions($institution, $grade)
            ->select('q.id AS questionId')
            ->addSelect('q.code AS code')
            ->addSelect('q.gradeLevel AS grade')
            ->addSelect('q.currentRevisionNumber AS currentRevisionNumber')
            ->addSelect('s.id AS subjectId')
            ->addSelect('s.name AS subjectName')
            ->addSelect('r.id AS revisionId')
            ->addSelect('r.revisionNumber AS revisionNumber')
            ->addSelect('r.type AS revisionType')
            ->orderBy('q.code', 'ASC');
        if ($includeStem) {
            $builder->addSelect('r.stemContent AS stemContent');
        }
        $rows = $builder->getQuery()->getArrayResult();
        $catalog = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $questionId = self::uuid($row['questionId'] ?? null);
            $revisionId = self::uuid($row['revisionId'] ?? null);
            $subjectId = self::uuid($row['subjectId'] ?? null);
            $gradeValue = self::gradeValue($row['grade'] ?? null);
            $currentRevisionNumber = self::intValue($row['currentRevisionNumber'] ?? null);
            $revisionNumber = self::intValue($row['revisionNumber'] ?? null);
            $type = self::questionTypeValue($row['revisionType'] ?? null);
            $code = $row['code'] ?? null;
            $subjectName = $row['subjectName'] ?? null;
            if (!$questionId instanceof Uuid
                || !$revisionId instanceof Uuid
                || !$subjectId instanceof Uuid
                || null === $gradeValue
                || null === $currentRevisionNumber
                || $currentRevisionNumber !== $revisionNumber
                || QuestionType::SingleChoice->value !== $type
                || !\is_string($code)
                || !\is_string($subjectName)
            ) {
                continue;
            }
            $stem = '';
            if ($includeStem) {
                $stemContent = $row['stemContent'] ?? null;
                if (\is_string($stemContent)) {
                    $decoded = json_decode($stemContent, true);
                    $stemContent = \is_array($decoded) ? $decoded : null;
                }
                $stem = \is_array($stemContent) ? self::stemLine($stemContent) : '';
            }
            $catalog[] = [
                'reference' => $this->hasher->workspaceReference('question', $questionId),
                'questionId' => $questionId->toRfc4122(),
                'revisionId' => $revisionId->toRfc4122(),
                'code' => $code,
                'grade' => $gradeValue,
                'subjectId' => $subjectId->toRfc4122(),
                'subjectName' => $subjectName,
                'stem' => $stem,
            ];
        }

        return $catalog;
    }

    private static function gradeValue(mixed $value): ?int
    {
        if ($value instanceof GradeLevel) {
            return $value->value;
        }
        if (\is_int($value) || (\is_string($value) && 1 === preg_match('/^\d+$/', $value))) {
            $grade = GradeLevel::tryFrom((int) $value);

            return $grade?->value;
        }

        return null;
    }

    private static function intValue(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        return null;
    }

    private static function questionTypeValue(mixed $value): ?string
    {
        if ($value instanceof QuestionType) {
            return $value->value;
        }
        if (\is_string($value)) {
            return $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function stemLine(array $document): string
    {
        $blocks = $document['blocks'] ?? null;
        if (!\is_array($blocks)) {
            return '';
        }
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $text = $block['text'] ?? null;
            if (\is_string($text) && '' !== trim($text)) {
                return mb_substr(trim($text), 0, 160);
            }
        }

        return '';
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

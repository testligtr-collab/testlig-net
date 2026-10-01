<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\InstitutionClassroomOption;
use App\Dto\InstitutionDeliveryResultPage;
use App\Dto\InstitutionDeliveryStudentResult;
use App\Dto\InstitutionTestAssignmentRow;
use App\Dto\TeacherClassroomCard;
use App\Entity\Assessment;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\AssessmentScoringRun;
use App\Entity\Classroom;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentDeliveryRecipientStatus;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\ClassroomStatus;
use App\Enum\CourseTeacherAssignmentStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\ScoringRunStatus;
use App\Enum\TeacherAssignmentStatus;
use App\Presentation\ResultPresentation;
use App\Repository\InstitutionMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read models for classroom assessment deliveries. No answer keys or emails.
 */
final class InstitutionDeliveryReport
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly InstitutionMembershipRepository $memberships,
        private readonly ResultPresentation $presentation,
    ) {
    }

    /**
     * @return list<InstitutionTestAssignmentRow>
     */
    public function assignments(Institution $institution, Assessment $assessment): array
    {
        /** @var list<AssessmentDelivery> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('delivery', 'classroom')
            ->from(AssessmentDelivery::class, 'delivery')
            ->innerJoin('delivery.classroom', 'classroom')
            ->andWhere('delivery.institution = :institution')
            ->andWhere('delivery.assessment = :assessment')
            ->andWhere('delivery.audienceType = :audience')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('assessment', $assessment->getId(), 'uuid')
            ->setParameter('audience', AssessmentDeliveryAudienceType::Classroom)
            ->orderBy('delivery.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
        $list = [];
        foreach ($rows as $delivery) {
            $classroom = $delivery->getClassroom();
            if (!$classroom instanceof Classroom) {
                continue;
            }
            $list[] = $this->row($delivery, $classroom);
        }

        return $list;
    }

    /**
     * @return list<InstitutionClassroomOption>
     */
    public function classroomChoices(User $actor, Institution $institution, Assessment $assessment): array
    {
        /** @var list<Classroom> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('classroom')
            ->from(Classroom::class, 'classroom')
            ->innerJoin('classroom.academicYear', 'year')
            ->andWhere('classroom.institution = :institution')
            ->andWhere('classroom.status = :classroomStatus')
            ->andWhere('year.status = :yearStatus')
            ->andWhere('classroom.gradeLevel = :grade')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('classroomStatus', ClassroomStatus::Active)
            ->setParameter('yearStatus', AcademicYearStatus::Active)
            ->setParameter('grade', $assessment->getGradeLevel())
            ->orderBy('classroom.name', 'ASC')
            ->getQuery()
            ->getResult();
        $membership = $this->memberships->findMembership($actor, $institution);
        $teacher = $membership instanceof InstitutionMembership
            && InstitutionMembershipRole::Teacher === $membership->getRole();
        $list = [];
        foreach ($rows as $classroom) {
            if ($teacher && !$this->teaches($actor, $classroom)) {
                continue;
            }
            $list[] = new InstitutionClassroomOption(
                $this->hasher->workspaceReference('classroom', $classroom->getId()),
                $classroom->getName(),
            );
        }

        return $list;
    }

    public function result(User $actor, AssessmentDelivery $delivery, int $page): ?InstitutionDeliveryResultPage
    {
        if (!$this->canRead($actor, $delivery)) {
            return null;
        }
        $page = max(1, $page);
        $recipientCount = $this->countRecipients($delivery);
        $pageCount = max(1, (int) ceil($recipientCount / self::PAGE_SIZE));
        if ($page > $pageCount) {
            $page = $pageCount;
        }
        $counts = $this->attemptCounts($delivery);
        $withAttempt = $counts['in_progress'] + $counts['completed'] + $counts['expired'];
        $percentages = $this->percentages($delivery);
        $average = $this->average($percentages);
        $classroom = $delivery->getClassroom();
        $revision = $delivery->getAssessmentPublication()->getAssessmentRevision();

        return new InstitutionDeliveryResultPage(
            $revision->getTitle(),
            $classroom instanceof Classroom ? $classroom->getName() : '',
            $this->window($delivery),
            $recipientCount,
            max(0, $recipientCount - $withAttempt),
            $counts['in_progress'],
            $counts['completed'],
            $counts['expired'],
            $this->presentation->percent($this->rate($counts['completed'], $recipientCount)) ?? '%0',
            $this->presentation->percent($average),
            $this->presentation->percent($this->extreme($percentages, true)),
            $this->presentation->percent($this->extreme($percentages, false)),
            $this->students($delivery, $page),
            $page,
            $pageCount,
        );
    }

    public function deliveryForInstitution(Institution $institution, string $reference): ?AssessmentDelivery
    {
        return $this->matchDelivery($reference, $institution, null);
    }

    public function deliveryForTeacher(User $actor, string $reference): ?AssessmentDelivery
    {
        return $this->matchDelivery($reference, null, $actor);
    }

    /**
     * @return list<TeacherClassroomCard>
     */
    public function teacherClassrooms(User $actor): array
    {
        /** @var list<ClassroomTeacherAssignment> $assignments */
        $assignments = $this->entityManager->createQueryBuilder()
            ->select('assignment', 'classroom', 'institution', 'membership')
            ->from(ClassroomTeacherAssignment::class, 'assignment')
            ->innerJoin('assignment.classroom', 'classroom')
            ->innerJoin('classroom.institution', 'institution')
            ->innerJoin('assignment.teacherMembership', 'membership')
            ->andWhere('membership.user = :actor')
            ->andWhere('membership.role = :role')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('assignment.status = :assignmentStatus')
            ->andWhere('classroom.status = :classroomStatus')
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->setParameter('role', InstitutionMembershipRole::Teacher)
            ->setParameter('membershipStatus', InstitutionMembershipStatus::Active)
            ->setParameter('assignmentStatus', TeacherAssignmentStatus::Active)
            ->setParameter('classroomStatus', ClassroomStatus::Active)
            ->orderBy('classroom.name', 'ASC')
            ->getQuery()
            ->getResult();
        $cards = [];
        foreach ($assignments as $assignment) {
            $classroom = $assignment->getClassroom();
            $institution = $classroom->getInstitution();
            $cards[] = new TeacherClassroomCard(
                $this->hasher->workspaceReference('classroom', $classroom->getId()),
                $classroom->getName(),
                $institution->getName(),
                $this->assignmentsForClassroom($classroom),
                $this->publishedTests($institution, $classroom),
            );
        }

        return $cards;
    }

    public function teacherInstitution(User $actor, string $classroomReference): ?Institution
    {
        $classroomReference = strtolower($classroomReference);
        /** @var list<ClassroomTeacherAssignment> $assignments */
        $assignments = $this->entityManager->createQueryBuilder()
            ->select('assignment', 'classroom', 'institution')
            ->from(ClassroomTeacherAssignment::class, 'assignment')
            ->innerJoin('assignment.classroom', 'classroom')
            ->innerJoin('classroom.institution', 'institution')
            ->innerJoin('assignment.teacherMembership', 'membership')
            ->andWhere('membership.user = :actor')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('assignment.status = :assignmentStatus')
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->setParameter('membershipStatus', InstitutionMembershipStatus::Active)
            ->setParameter('assignmentStatus', TeacherAssignmentStatus::Active)
            ->getQuery()
            ->getResult();
        foreach ($assignments as $assignment) {
            $classroom = $assignment->getClassroom();
            if (hash_equals($this->hasher->workspaceReference('classroom', $classroom->getId()), $classroomReference)) {
                return $classroom->getInstitution();
            }
        }

        return null;
    }

    private function canRead(User $actor, AssessmentDelivery $delivery): bool
    {
        if (AssessmentDeliveryAudienceType::Classroom !== $delivery->getAudienceType()) {
            return false;
        }
        if (AssessmentScope::Institution !== $delivery->getAssessment()->getScope()) {
            return false;
        }
        $membership = $this->memberships->findMembership($actor, $delivery->getInstitution());
        if (!$membership instanceof InstitutionMembership || InstitutionMembershipStatus::Active !== $membership->getStatus()) {
            return false;
        }
        if (\in_array($membership->getRole(), [InstitutionMembershipRole::Owner, InstitutionMembershipRole::Manager], true)) {
            return true;
        }
        $classroom = $delivery->getClassroom();

        return InstitutionMembershipRole::Teacher === $membership->getRole()
            && $classroom instanceof Classroom
            && $this->teaches($actor, $classroom);
    }

    private function teaches(User $actor, Classroom $classroom): bool
    {
        $connection = $this->entityManager->getConnection();
        $params = [
            'classroomId' => $classroom->getId()->toBinary(),
            'userId' => $actor->getId()->toBinary(),
            'membershipStatus' => InstitutionMembershipStatus::Active->value,
        ];
        $homeroom = $connection->fetchOne(
            'SELECT 1 FROM classroom_teacher_assignments a
             INNER JOIN institution_memberships m ON m.id = a.teacher_membership_id
             WHERE a.classroom_id = :classroomId AND m.user_id = :userId
               AND a.status = :status AND m.status = :membershipStatus
             LIMIT 1',
            $params + ['status' => TeacherAssignmentStatus::Active->value],
        );
        if (false !== $homeroom) {
            return true;
        }
        $course = $connection->fetchOne(
            'SELECT 1 FROM course_teacher_assignments a
             INNER JOIN institution_memberships m ON m.id = a.teacher_membership_id
             INNER JOIN classroom_courses cc ON cc.id = a.classroom_course_id
             WHERE cc.classroom_id = :classroomId AND m.user_id = :userId
               AND a.status = :status AND m.status = :membershipStatus
             LIMIT 1',
            $params + ['status' => CourseTeacherAssignmentStatus::Active->value],
        );

        return false !== $course;
    }

    private function row(AssessmentDelivery $delivery, Classroom $classroom): InstitutionTestAssignmentRow
    {
        $status = $delivery->getStatus();
        $revision = $delivery->getAssessmentPublication()->getAssessmentRevision();

        return new InstitutionTestAssignmentRow(
            $this->hasher->workspaceReference('delivery', $delivery->getId()),
            $classroom->getName(),
            match ($status) {
                AssessmentDeliveryStatus::Draft => 'Taslak',
                AssessmentDeliveryStatus::Active => 'Aktif',
                AssessmentDeliveryStatus::Closed => 'Kapandı',
                AssessmentDeliveryStatus::Cancelled => 'İptal',
            },
            $this->stamp($delivery->getOpensAt()),
            $this->closesLabel($delivery),
            $this->countRecipients($delivery),
            AssessmentDeliveryStatus::Draft === $status,
            AssessmentDeliveryStatus::Draft === $status || AssessmentDeliveryStatus::Active === $status,
            $revision->getTitle(),
        );
    }

    /**
     * @return list<InstitutionTestAssignmentRow>
     */
    private function assignmentsForClassroom(Classroom $classroom): array
    {
        /** @var list<AssessmentDelivery> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('delivery')
            ->from(AssessmentDelivery::class, 'delivery')
            ->andWhere('delivery.classroom = :classroom')
            ->andWhere('delivery.audienceType = :audience')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->setParameter('audience', AssessmentDeliveryAudienceType::Classroom)
            ->orderBy('delivery.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
        $list = [];
        foreach ($rows as $delivery) {
            $list[] = $this->row($delivery, $classroom);
        }

        return $list;
    }

    /**
     * @return list<InstitutionClassroomOption>
     */
    private function publishedTests(Institution $institution, Classroom $classroom): array
    {
        /** @var list<Assessment> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('assessment', 'revision')
            ->from(Assessment::class, 'assessment')
            ->innerJoin('assessment.publishedRevision', 'revision')
            ->andWhere('assessment.institution = :institution')
            ->andWhere('assessment.scope = :scope')
            ->andWhere('assessment.status = :status')
            ->andWhere('assessment.gradeLevel = :grade')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('scope', AssessmentScope::Institution)
            ->setParameter('status', AssessmentStatus::Published)
            ->setParameter('grade', $classroom->getGradeLevel())
            ->orderBy('revision.title', 'ASC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
        $list = [];
        foreach ($rows as $assessment) {
            $revision = $assessment->getPublishedRevision();
            $list[] = new InstitutionClassroomOption(
                $this->hasher->workspaceReference('assessment', $assessment->getId()),
                $revision?->getTitle() ?? 'Test',
            );
        }

        return $list;
    }

    private function matchDelivery(string $reference, ?Institution $institution, ?User $teacher): ?AssessmentDelivery
    {
        $reference = strtolower($reference);
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return null;
        }
        $qb = $this->entityManager->createQueryBuilder()
            ->select('delivery', 'assessment', 'classroom')
            ->from(AssessmentDelivery::class, 'delivery')
            ->innerJoin('delivery.assessment', 'assessment')
            ->leftJoin('delivery.classroom', 'classroom')
            ->andWhere('delivery.audienceType = :audience')
            ->andWhere('assessment.scope = :scope')
            ->setParameter('audience', AssessmentDeliveryAudienceType::Classroom)
            ->setParameter('scope', AssessmentScope::Institution);
        if ($institution instanceof Institution) {
            $qb->andWhere('delivery.institution = :institution')
                ->setParameter('institution', $institution->getId(), 'uuid');
        }
        /** @var list<AssessmentDelivery> $rows */
        $rows = $qb->getQuery()->getResult();
        foreach ($rows as $delivery) {
            if (!hash_equals($this->hasher->workspaceReference('delivery', $delivery->getId()), $reference)) {
                continue;
            }
            if ($teacher instanceof User && !$this->canRead($teacher, $delivery)) {
                return null;
            }

            return $delivery;
        }

        return null;
    }

    private function countRecipients(AssessmentDelivery $delivery): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(recipient.id)')
            ->from(AssessmentDeliveryRecipient::class, 'recipient')
            ->andWhere('recipient.delivery = :delivery')
            ->andWhere('recipient.status = :status')
            ->setParameter('delivery', $delivery->getId(), 'uuid')
            ->setParameter('status', AssessmentDeliveryRecipientStatus::Eligible)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array{in_progress: int, completed: int, expired: int}
     */
    private function attemptCounts(AssessmentDelivery $delivery): array
    {
        /** @var list<array{status: AssessmentAttemptStatus|string, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('attempt.status AS status, COUNT(attempt.id) AS total')
            ->from(\App\Entity\AssessmentAttempt::class, 'attempt')
            ->andWhere('attempt.delivery = :delivery')
            ->setParameter('delivery', $delivery->getId(), 'uuid')
            ->groupBy('attempt.status')
            ->getQuery()
            ->getArrayResult();
        $counts = ['in_progress' => 0, 'completed' => 0, 'expired' => 0];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof AssessmentAttemptStatus ? $row['status']->value : (string) $row['status'];
            $total = (int) $row['total'];
            if (AssessmentAttemptStatus::InProgress->value === $status) {
                $counts['in_progress'] = $total;
            } elseif (AssessmentAttemptStatus::Submitted->value === $status) {
                $counts['completed'] = $total;
            } elseif (AssessmentAttemptStatus::Expired->value === $status) {
                $counts['expired'] = $total;
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function percentages(AssessmentDelivery $delivery): array
    {
        /** @var list<array{percentage: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('run.percentage AS percentage')
            ->from(AssessmentScoringRun::class, 'run')
            ->andWhere('run.delivery = :delivery')
            ->andWhere('run.status = :status')
            ->setParameter('delivery', $delivery->getId(), 'uuid')
            ->setParameter('status', ScoringRunStatus::Completed)
            ->getQuery()
            ->getArrayResult();
        $values = [];
        foreach ($rows as $row) {
            $values[] = $row['percentage'];
        }

        return $values;
    }

    /**
     * @param list<string> $percentages
     */
    private function average(array $percentages): ?string
    {
        if ([] === $percentages) {
            return null;
        }
        $sum = '0.0000';
        foreach ($percentages as $percentage) {
            $normalized = $this->decimal($percentage);
            if (null === $normalized) {
                continue;
            }
            $sum = bcadd($sum, $normalized, 4);
        }

        return bcdiv($sum, (string) \count($percentages), 4);
    }

    /**
     * @param list<string> $percentages
     */
    private function extreme(array $percentages, bool $highest): ?string
    {
        $picked = null;
        foreach ($percentages as $percentage) {
            $normalized = $this->decimal($percentage);
            if (null === $normalized) {
                continue;
            }
            if (null === $picked) {
                $picked = $normalized;
                continue;
            }
            $order = bccomp($normalized, $picked, 4);
            if ($highest ? $order > 0 : $order < 0) {
                $picked = $normalized;
            }
        }

        return $picked;
    }

    private function rate(int $completed, int $recipients): string
    {
        if ($recipients < 1) {
            return '0.00';
        }

        return bcmul(bcdiv((string) $completed, (string) $recipients, 8), '100', 2);
    }

    /**
     * @return numeric-string|null
     */
    private function decimal(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ('' === $trimmed || !is_numeric($trimmed) || 1 !== preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            return null;
        }
        if (!\extension_loaded('bcmath')) {
            return null;
        }

        return bcadd($trimmed, '0', 4);
    }

    /**
     * @return list<InstitutionDeliveryStudentResult>
     */
    private function students(AssessmentDelivery $delivery, int $page): array
    {
        /** @var list<AssessmentDeliveryRecipient> $recipients */
        $recipients = $this->entityManager->createQueryBuilder()
            ->select('recipient', 'membership', 'student')
            ->from(AssessmentDeliveryRecipient::class, 'recipient')
            ->innerJoin('recipient.studentMembership', 'membership')
            ->innerJoin('membership.user', 'student')
            ->andWhere('recipient.delivery = :delivery')
            ->andWhere('recipient.status = :status')
            ->setParameter('delivery', $delivery->getId(), 'uuid')
            ->setParameter('status', AssessmentDeliveryRecipientStatus::Eligible)
            ->orderBy('student.lastName', 'ASC')
            ->addOrderBy('student.firstName', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();
        if ([] === $recipients) {
            return [];
        }
        $attempts = $this->attemptsFor($delivery, $recipients);
        $runs = $this->runsFor(array_values($attempts));
        $list = [];
        foreach ($recipients as $recipient) {
            $user = $recipient->getStudentMembership()->getUser();
            $attempt = $attempts[$recipient->getId()->toRfc4122()] ?? null;
            $run = null;
            if (null !== $attempt) {
                $run = $runs[$attempt->getId()->toRfc4122()] ?? null;
            }
            $list[] = new InstitutionDeliveryStudentResult(
                trim($user->getFirstName().' '.$user->getLastName()),
                $this->statusLabel($attempt),
                null === $attempt ? null : $this->stamp($attempt->getStartedAt()),
                null === $attempt ? null : $this->stamp($attempt->getSubmittedAt() ?? $attempt->getExpiredAt()),
                $run?->getCorrectCount(),
                $run?->getIncorrectCount(),
                $run?->getUnansweredCount(),
                $this->presentation->points($run?->getFinalPoints()),
                $this->presentation->points($run?->getMaximumPoints()),
                $this->presentation->percent($run?->getPercentage()),
            );
        }

        return $list;
    }

    /**
     * @param list<AssessmentDeliveryRecipient> $recipients
     *
     * @return array<string, \App\Entity\AssessmentAttempt>
     */
    private function attemptsFor(AssessmentDelivery $delivery, array $recipients): array
    {
        $ids = [];
        foreach ($recipients as $index => $recipient) {
            $ids['recipient'.$index] = $recipient->getId();
        }
        $qb = $this->entityManager->createQueryBuilder()
            ->select('attempt', 'recipient')
            ->from(\App\Entity\AssessmentAttempt::class, 'attempt')
            ->innerJoin('attempt.recipient', 'recipient')
            ->andWhere('attempt.delivery = :delivery')
            ->setParameter('delivery', $delivery->getId(), 'uuid');
        $clauses = [];
        foreach ($ids as $name => $id) {
            $clauses[] = 'recipient.id = :'.$name;
            $qb->setParameter($name, $id, 'uuid');
        }
        /** @var list<\App\Entity\AssessmentAttempt> $rows */
        $rows = $qb->andWhere(implode(' OR ', $clauses))->getQuery()->getResult();
        $map = [];
        foreach ($rows as $attempt) {
            $map[$attempt->getRecipient()->getId()->toRfc4122()] = $attempt;
        }

        return $map;
    }

    /**
     * @param list<\App\Entity\AssessmentAttempt> $attempts
     *
     * @return array<string, AssessmentScoringRun>
     */
    private function runsFor(array $attempts): array
    {
        if ([] === $attempts) {
            return [];
        }
        $qb = $this->entityManager->createQueryBuilder()
            ->select('run', 'attempt')
            ->from(AssessmentScoringRun::class, 'run')
            ->innerJoin('run.attempt', 'attempt')
            ->andWhere('run.status = :completed')
            ->setParameter('completed', ScoringRunStatus::Completed)
            ->orderBy('run.runNumber', 'DESC');
        $clauses = [];
        foreach ($attempts as $index => $attempt) {
            $name = 'attempt'.$index;
            $clauses[] = 'attempt.id = :'.$name;
            $qb->setParameter($name, $attempt->getId(), 'uuid');
        }
        /** @var list<AssessmentScoringRun> $rows */
        $rows = $qb->andWhere(implode(' OR ', $clauses))->getQuery()->getResult();
        $map = [];
        foreach ($rows as $run) {
            $key = $run->getAttempt()->getId()->toRfc4122();
            if (!isset($map[$key])) {
                $map[$key] = $run;
            }
        }

        return $map;
    }

    private function statusLabel(?\App\Entity\AssessmentAttempt $attempt): string
    {
        if (null === $attempt) {
            return 'Başlamadı';
        }

        return match ($attempt->getStatus()) {
            AssessmentAttemptStatus::InProgress => 'Devam ediyor',
            AssessmentAttemptStatus::Submitted => 'Tamamlandı',
            AssessmentAttemptStatus::Expired => 'Süresi doldu',
            AssessmentAttemptStatus::Cancelled => 'İptal',
        };
    }

    private function window(AssessmentDelivery $delivery): string
    {
        return $this->stamp($delivery->getOpensAt()).' — '.$this->closesLabel($delivery);
    }

    private function closesLabel(AssessmentDelivery $delivery): string
    {
        if ((int) $delivery->getClosesAt()->format('Y') >= 9999) {
            return 'Bitiş yok';
        }

        return $this->stamp($delivery->getClosesAt());
    }

    private function stamp(?\DateTimeImmutable $at): string
    {
        if (null === $at) {
            return '';
        }

        return $this->presentation->instant($at) ?? '';
    }
}

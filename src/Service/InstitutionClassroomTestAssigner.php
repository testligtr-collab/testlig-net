<?php

declare(strict_types=1);

namespace App\Service;

use App\Assessment\AssessmentScore;
use App\Entity\Assessment;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentItem;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentRevision;
use App\Entity\Classroom;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentDeliveryFailureReason;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\ClassroomStatus;
use App\Enum\CourseTeacherAssignmentStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\QuestionType;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\SubjectStatus;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserStatus;
use App\Exception\AssessmentDeliveryException;
use App\Exception\AssessmentException;
use App\Exception\InstitutionTestAssignmentException;
use App\Repository\AssessmentPublicationRepository;
use App\Repository\InstitutionMembershipRepository;
use App\Time\UtcInstant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Assigns a published institution assessment to one classroom.
 *
 * Uses AssessmentDeliveryManager. Does not treat a global SuperAdmin role as membership.
 */
final class InstitutionClassroomTestAssigner
{
    public const MAX_ATTEMPTS = 1;

    private const OPEN_ENDED = '9999-01-01 00:00:00';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly AssessmentDeliveryManager $deliveries,
        private readonly AssessmentPublicationRepository $publications,
        private readonly InstitutionMembershipRepository $memberships,
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createDraft(
        User $actor,
        Institution $institution,
        string $assessmentReference,
        string $classroomReference,
        ?string $opensRaw,
        ?string $closesRaw,
        ?string $instructions,
    ): string {
        $assessment = $this->assessmentIn($institution, $assessmentReference);
        $classroom = $this->classroomIn($institution, $classroomReference);
        $this->assertActor($actor, $institution, $classroom);
        $this->assertAssignable($assessment, $classroom);
        $opensAt = $this->parseInstant($opensRaw, UtcInstant::ensure($this->clock->now()));
        $closesAt = $this->parseInstant($closesRaw, new \DateTimeImmutable(self::OPEN_ENDED, new \DateTimeZone('UTC')));
        if ($opensAt >= $closesAt) {
            throw InstitutionTestAssignmentException::window();
        }
        if ($this->eligibleCount($classroom) < 1) {
            throw InstitutionTestAssignmentException::emptyClass();
        }
        if ($this->hasOpenDelivery($assessment, $classroom)) {
            throw InstitutionTestAssignmentException::overlap();
        }

        $publication = $this->publicationFor($assessment);
        try {
            $delivery = $this->entityManager->wrapInTransaction(function () use (
                $actor,
                $institution,
                $assessment,
                $classroom,
                $publication,
                $opensAt,
                $closesAt,
                $instructions,
            ): AssessmentDelivery {
                $this->entityManager->lock($institution, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->lock($classroom, LockMode::PESSIMISTIC_WRITE);
                if ($this->eligibleCount($classroom) < 1) {
                    throw InstitutionTestAssignmentException::emptyClass();
                }
                if ($this->hasOpenDelivery($assessment, $classroom)) {
                    throw InstitutionTestAssignmentException::overlap();
                }

                return $this->deliveries->createDraft(
                    $institution,
                    $publication,
                    AssessmentDeliveryAudienceType::Classroom,
                    $classroom,
                    null,
                    $actor,
                    $opensAt,
                    $closesAt,
                    self::MAX_ATTEMPTS,
                    null,
                    $instructions,
                    'classroom_assign',
                );
            });
        } catch (InstitutionTestAssignmentException $exception) {
            throw $exception;
        } catch (AssessmentDeliveryException) {
            throw InstitutionTestAssignmentException::notAssignable();
        }

        return $this->hasher->workspaceReference('delivery', $delivery->getId());
    }

    public function activate(User $actor, Institution $institution, string $deliveryReference): void
    {
        $delivery = $this->deliveryIn($institution, $deliveryReference);
        $classroom = $delivery->getClassroom();
        if (!$classroom instanceof Classroom) {
            throw InstitutionTestAssignmentException::notFound();
        }
        $this->assertActor($actor, $institution, $classroom);
        try {
            $this->deliveries->activate($delivery, $actor, 'classroom_activate');
        } catch (AssessmentDeliveryException $exception) {
            if (AssessmentDeliveryFailureReason::NoEligibleRecipients === $exception->getReason()) {
                throw InstitutionTestAssignmentException::emptyClass();
            }
            throw InstitutionTestAssignmentException::notAssignable();
        }
    }

    public function close(User $actor, Institution $institution, string $deliveryReference): void
    {
        $delivery = $this->deliveryIn($institution, $deliveryReference);
        $classroom = $delivery->getClassroom();
        if (!$classroom instanceof Classroom) {
            throw InstitutionTestAssignmentException::notFound();
        }
        $this->assertActor($actor, $institution, $classroom);
        try {
            if (AssessmentDeliveryStatus::Draft === $delivery->getStatus()) {
                $this->deliveries->cancel($delivery, $actor, 'classroom_cancel', 'classroom_cancel');
            } else {
                $this->deliveries->close($delivery, $actor, 'classroom_close');
            }
        } catch (AssessmentDeliveryException) {
            throw InstitutionTestAssignmentException::notAssignable();
        }
    }

    private function assertActor(User $actor, Institution $institution, Classroom $classroom): void
    {
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            throw InstitutionTestAssignmentException::forbidden();
        }
        $membership = $this->memberships->findMembership($actor, $institution);
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw InstitutionTestAssignmentException::forbidden();
        }
        if (\in_array($membership->getRole(), [InstitutionMembershipRole::Owner, InstitutionMembershipRole::Manager], true)) {
            return;
        }
        if (InstitutionMembershipRole::Teacher !== $membership->getRole()
            || !$this->teacherAssigned($actor->getId(), $classroom->getId())
        ) {
            throw InstitutionTestAssignmentException::forbidden();
        }
    }

    private function assertAssignable(Assessment $assessment, Classroom $classroom): void
    {
        if (AssessmentScope::Institution !== $assessment->getScope()
            || AssessmentStatus::Published !== $assessment->getStatus()
            || $assessment->getGradeLevel() !== $classroom->getGradeLevel()
        ) {
            throw InstitutionTestAssignmentException::notAssignable();
        }
        $owner = $assessment->getInstitution();
        if (!$owner instanceof Institution || !$owner->getId()->equals($classroom->getInstitution()->getId())) {
            throw InstitutionTestAssignmentException::notFound();
        }
        if (ClassroomStatus::Active !== $classroom->getStatus()) {
            throw InstitutionTestAssignmentException::notAssignable();
        }
        $year = $classroom->getAcademicYear();
        if (AcademicYearStatus::Active !== $year->getStatus()) {
            throw InstitutionTestAssignmentException::notAssignable();
        }
        $subject = $assessment->getSubject();
        if (null === $subject || SubjectStatus::Active !== $subject->getStatus()) {
            throw InstitutionTestAssignmentException::notAssignable();
        }
        $revision = $assessment->getPublishedRevision();
        if (!$revision instanceof AssessmentRevision || !$revision->isSealed()) {
            throw InstitutionTestAssignmentException::notAssignable();
        }
        $this->assertItems($revision);
    }

    private function assertItems(AssessmentRevision $revision): void
    {
        /** @var list<AssessmentItem> $items */
        $items = $this->entityManager->createQueryBuilder()
            ->select('item', 'questionRevision')
            ->from(AssessmentItem::class, 'item')
            ->innerJoin('item.questionRevision', 'questionRevision')
            ->andWhere('item.assessmentRevision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->getQuery()
            ->getResult();
        if ([] === $items) {
            throw InstitutionTestAssignmentException::notAssignable();
        }
        foreach ($items as $item) {
            if (QuestionType::SingleChoice !== $item->getQuestionRevision()->getType()) {
                throw InstitutionTestAssignmentException::notAssignable();
            }
            try {
                $points = AssessmentScore::normalizePoints($item->getPoints());
                $penalty = AssessmentScore::normalizePenalty($item->getPenaltyPoints(), $points);
            } catch (AssessmentException) {
                throw InstitutionTestAssignmentException::notAssignable();
            }
            if (0 !== bccomp($penalty, '0', 2)) {
                throw InstitutionTestAssignmentException::notAssignable();
            }
        }
    }

    private function publicationFor(Assessment $assessment): AssessmentPublication
    {
        $revision = $assessment->getPublishedRevision();
        if (!$revision instanceof AssessmentRevision) {
            throw InstitutionTestAssignmentException::notAssignable();
        }
        $publication = $this->publications->findOneBy([
            'assessment' => $assessment,
            'assessmentRevision' => $revision,
        ], ['publicationNumber' => 'DESC']);
        if (!$publication instanceof AssessmentPublication) {
            throw InstitutionTestAssignmentException::notAssignable();
        }

        return $publication;
    }

    private function eligibleCount(Classroom $classroom): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(enrollment.id)')
            ->from(\App\Entity\ClassroomStudentEnrollment::class, 'enrollment')
            ->innerJoin('enrollment.studentMembership', 'membership')
            ->innerJoin('membership.user', 'student')
            ->andWhere('enrollment.classroom = :classroom')
            ->andWhere('enrollment.status = :enrollmentStatus')
            ->andWhere('membership.role = :role')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('student.status = :userStatus')
            ->andWhere('student.emailVerifiedAt IS NOT NULL')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->setParameter('enrollmentStatus', StudentEnrollmentStatus::Active)
            ->setParameter('role', InstitutionMembershipRole::Student)
            ->setParameter('membershipStatus', InstitutionMembershipStatus::Active)
            ->setParameter('userStatus', UserStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function hasOpenDelivery(Assessment $assessment, Classroom $classroom): bool
    {
        $year = $classroom->getAcademicYear();

        return 0 < (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(delivery.id)')
            ->from(AssessmentDelivery::class, 'delivery')
            ->innerJoin('delivery.classroom', 'classroom')
            ->andWhere('delivery.assessment = :assessment')
            ->andWhere('delivery.classroom = :classroom')
            ->andWhere('classroom.academicYear = :year')
            ->andWhere('delivery.status IN (:statuses)')
            ->setParameter('assessment', $assessment->getId(), 'uuid')
            ->setParameter('classroom', $classroom->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('statuses', [AssessmentDeliveryStatus::Draft, AssessmentDeliveryStatus::Active])
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function teacherAssigned(Uuid $userId, Uuid $classroomId): bool
    {
        $homeroom = $this->connection->fetchOne(
            'SELECT 1 FROM classroom_teacher_assignments a
             INNER JOIN institution_memberships m ON m.id = a.teacher_membership_id
             WHERE a.classroom_id = :classroomId AND m.user_id = :userId
               AND a.status = :status AND m.status = :membershipStatus AND m.role = :role
             LIMIT 1',
            [
                'classroomId' => $classroomId->toBinary(),
                'userId' => $userId->toBinary(),
                'status' => TeacherAssignmentStatus::Active->value,
                'membershipStatus' => InstitutionMembershipStatus::Active->value,
                'role' => InstitutionMembershipRole::Teacher->value,
            ],
        );
        if (false !== $homeroom) {
            return true;
        }
        $course = $this->connection->fetchOne(
            'SELECT 1 FROM course_teacher_assignments a
             INNER JOIN institution_memberships m ON m.id = a.teacher_membership_id
             INNER JOIN classroom_courses cc ON cc.id = a.classroom_course_id
             WHERE cc.classroom_id = :classroomId AND m.user_id = :userId
               AND a.status = :status AND m.status = :membershipStatus AND m.role = :role
             LIMIT 1',
            [
                'classroomId' => $classroomId->toBinary(),
                'userId' => $userId->toBinary(),
                'status' => CourseTeacherAssignmentStatus::Active->value,
                'membershipStatus' => InstitutionMembershipStatus::Active->value,
                'role' => InstitutionMembershipRole::Teacher->value,
            ],
        );

        return false !== $course;
    }

    private function assessmentIn(Institution $institution, string $reference): Assessment
    {
        /** @var list<Assessment> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('assessment')
            ->from(Assessment::class, 'assessment')
            ->andWhere('assessment.institution = :institution')
            ->andWhere('assessment.scope = :scope')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('scope', AssessmentScope::Institution)
            ->getQuery()
            ->getResult();
        foreach ($rows as $assessment) {
            if (hash_equals($this->hasher->workspaceReference('assessment', $assessment->getId()), strtolower($reference))) {
                return $assessment;
            }
        }

        throw InstitutionTestAssignmentException::notFound();
    }

    private function classroomIn(Institution $institution, string $reference): Classroom
    {
        /** @var list<Classroom> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('classroom')
            ->from(Classroom::class, 'classroom')
            ->andWhere('classroom.institution = :institution')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->getQuery()
            ->getResult();
        foreach ($rows as $classroom) {
            if (hash_equals($this->hasher->workspaceReference('classroom', $classroom->getId()), strtolower($reference))) {
                return $classroom;
            }
        }

        throw InstitutionTestAssignmentException::notFound();
    }

    private function deliveryIn(Institution $institution, string $reference): AssessmentDelivery
    {
        /** @var list<AssessmentDelivery> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('delivery')
            ->from(AssessmentDelivery::class, 'delivery')
            ->andWhere('delivery.institution = :institution')
            ->andWhere('delivery.audienceType = :audience')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('audience', AssessmentDeliveryAudienceType::Classroom)
            ->getQuery()
            ->getResult();
        foreach ($rows as $delivery) {
            if (hash_equals($this->hasher->workspaceReference('delivery', $delivery->getId()), strtolower($reference))) {
                return $delivery;
            }
        }

        throw InstitutionTestAssignmentException::notFound();
    }

    private function parseInstant(?string $raw, \DateTimeImmutable $fallback): \DateTimeImmutable
    {
        $raw = trim((string) $raw);
        if ('' === $raw) {
            return UtcInstant::ensure($fallback);
        }
        $zone = new \DateTimeZone('Europe/Istanbul');
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, $zone)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $raw, $zone);
        if (!$parsed instanceof \DateTimeImmutable) {
            throw InstitutionTestAssignmentException::window();
        }

        return UtcInstant::ensure($parsed);
    }
}

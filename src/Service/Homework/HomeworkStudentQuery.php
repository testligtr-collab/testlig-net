<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Dto\HomeworkStudentDetail;
use App\Dto\HomeworkStudentListRow;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\HomeworkAssignment;
use App\Entity\HomeworkRecipient;
use App\Entity\HomeworkSubmission;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\ClassroomStatus;
use App\Enum\HomeworkSubmissionStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\StudentEnrollmentStatus;
use App\Service\ActiveVerifiedUserPolicy;
use App\Time\UtcInstant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Student homework reads. GET never creates submission rows or writes audit.
 */
final class HomeworkStudentQuery
{
    private const DISPLAY_TZ = 'Europe/Istanbul';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HomeworkStudentScope $scope,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Open assignments for the actor plus own submitted history outside current class scope.
     *
     * @return list<HomeworkStudentListRow>
     */
    public function listFor(User $actor): array
    {
        if (!$this->activeUsers->isActiveAndVerified($actor)) {
            return [];
        }

        $now = UtcInstant::ensure($this->clock->now());

        /** @var list<HomeworkRecipient> $recipients */
        $recipients = $this->entityManager->createQueryBuilder()
            ->select('r', 'a', 'c')
            ->from(HomeworkRecipient::class, 'r')
            ->innerJoin('r.assignment', 'a')
            ->innerJoin('a.classroom', 'c')
            ->andWhere('r.studentUser = :actor')
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->orderBy('a.dueAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        // Load by studentUser (= typed uuid), not recipient IN (:ids): binary UUID lists do not
        // bind through an untyped DQL IN on MariaDB, which left every list row as "Teslim edilmedi".
        $submissionsByRecipient = $this->submissionsForActor($actor);
        $openClassroomIds = $this->openClassroomIdsFor($actor);

        $rows = [];
        foreach ($recipients as $recipient) {
            $assignment = $recipient->getAssignment();
            $submission = $submissionsByRecipient[$recipient->getId()->toRfc4122()] ?? null;
            $inScope = isset($openClassroomIds[$assignment->getClassroom()->getId()->toRfc4122()]);
            $submitted = $submission instanceof HomeworkSubmission
                && HomeworkSubmissionStatus::Submitted === $submission->getStatus();

            if ($inScope) {
                $rows[] = $this->listRow($assignment, $submission, $now, false, '');
                continue;
            }
            if ($submitted) {
                $rows[] = $this->listRow(
                    $assignment,
                    $submission,
                    $now,
                    true,
                    'Bu ödev artık aktif sınıf kapsamında değil; yalnız gönderilmiş yanıtın salt okunur.',
                );
            }
        }

        return $rows;
    }

    /**
     * @return array<string, true> classroom uuid => true
     */
    private function openClassroomIdsFor(User $actor): array
    {
        /** @var list<array{id: Uuid|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.id AS id')
            ->from(ClassroomStudentEnrollment::class, 'e')
            ->innerJoin('e.studentMembership', 'm')
            ->innerJoin('e.classroom', 'c')
            ->innerJoin('c.institution', 'i')
            ->andWhere('e.status = :enrollmentStatus')
            ->andWhere('m.user = :actor')
            ->andWhere('m.institution = i')
            ->andWhere('m.status = :membershipStatus')
            ->andWhere('m.role = :membershipRole')
            ->andWhere('i.status = :institutionStatus')
            ->setParameter('enrollmentStatus', StudentEnrollmentStatus::Active)
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->setParameter('membershipStatus', InstitutionMembershipStatus::Active)
            ->setParameter('membershipRole', InstitutionMembershipRole::Student)
            ->setParameter('institutionStatus', InstitutionStatus::Active)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            $map[$id instanceof Uuid ? $id->toRfc4122() : (16 === \strlen((string) $id) ? Uuid::fromBinary((string) $id)->toRfc4122() : (string) $id)] = true;
        }

        return $map;
    }

    public function detailFor(User $actor, Uuid $assignmentId): ?HomeworkStudentDetail
    {
        if (!$this->activeUsers->isActiveAndVerified($actor)) {
            return null;
        }

        $now = UtcInstant::ensure($this->clock->now());
        $recipient = $this->scope->findRecipient($actor, $assignmentId, false);

        if ($recipient instanceof HomeworkRecipient) {
            $assignment = $recipient->getAssignment();
            $submission = $this->scope->findSubmissionForRecipient($recipient, false);
            $openEnrollment = $this->scope->findOpenEnrollment($actor, $assignment, false, false);
            if (null !== $openEnrollment) {
                $writableEnrollment = $this->scope->findOpenEnrollment($actor, $assignment, true, false);

                return $this->detail($assignment, $submission, $now, true, null !== $writableEnrollment);
            }
            if ($submission instanceof HomeworkSubmission
                && HomeworkSubmissionStatus::Submitted === $submission->getStatus()
            ) {
                return $this->detail($assignment, $submission, $now, false, false);
            }

            return null;
        }

        $ownSubmitted = $this->scope->findOwnSubmitted($actor, $assignmentId);
        if ($ownSubmitted instanceof HomeworkSubmission) {
            return $this->detail($ownSubmitted->getAssignment(), $ownSubmitted, $now, false, false);
        }

        return null;
    }

    /**
     * @return array<string, HomeworkSubmission> recipient uuid => submission
     */
    private function submissionsForActor(User $actor): array
    {
        /** @var list<HomeworkSubmission> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s', 'r')
            ->from(HomeworkSubmission::class, 's')
            ->innerJoin('s.recipient', 'r')
            ->andWhere('s.studentUser = :actor')
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->getRecipient()->getId()->toRfc4122()] = $row;
        }

        return $map;
    }

    private function listRow(
        HomeworkAssignment $assignment,
        ?HomeworkSubmission $submission,
        \DateTimeImmutable $now,
        bool $outsideCurrentScope,
        string $outsideScopeNote,
    ): HomeworkStudentListRow {
        [$statusKey, $statusLabel, $actionLabel] = $this->statusParts($submission);
        $duePassed = $now > $assignment->getDueAt();

        return new HomeworkStudentListRow(
            assignmentId: $assignment->getId()->toRfc4122(),
            title: $assignment->getTitle(),
            classroomName: $assignment->getClassroom()->getName(),
            dueAtLocal: $this->formatLocal($assignment->getDueAt()),
            duePassed: $duePassed,
            statusKey: $statusKey,
            statusLabel: $statusLabel,
            actionLabel: $actionLabel,
            outsideCurrentScope: $outsideCurrentScope,
            outsideScopeNote: $outsideScopeNote,
        );
    }

    private function detail(
        HomeworkAssignment $assignment,
        ?HomeworkSubmission $submission,
        \DateTimeImmutable $now,
        bool $inCurrentScope,
        bool $openScope,
    ): HomeworkStudentDetail {
        [$statusKey, $statusLabel] = \array_slice($this->statusParts($submission), 0, 2);
        $duePassed = $now > $assignment->getDueAt();
        $submitted = false;
        $submittedAtLocal = null;
        if ($submission instanceof HomeworkSubmission
            && HomeworkSubmissionStatus::Submitted === $submission->getStatus()
        ) {
            $submitted = true;
            $submittedAt = $submission->getSubmittedAt();
            $submittedAtLocal = null !== $submittedAt ? $this->formatLocal($submittedAt) : null;
        }
        $writable = $inCurrentScope && $openScope && !$submitted && !$duePassed
            && $this->hierarchyActive($assignment);

        $closedReason = '';
        if (!$submitted) {
            if ($duePassed) {
                $closedReason = 'Teslim süresi sona erdi. Kaydetme ve gönderme kapandı.';
            } elseif (!$writable) {
                $closedReason = 'Bu ödeve şu an yazılamaz.';
            }
        }

        return new HomeworkStudentDetail(
            assignmentId: $assignment->getId()->toRfc4122(),
            title: $assignment->getTitle(),
            description: $assignment->getDescription(),
            classroomName: $assignment->getClassroom()->getName(),
            dueAtLocal: $this->formatLocal($assignment->getDueAt()),
            duePassed: $duePassed,
            statusKey: $statusKey,
            statusLabel: $statusLabel,
            body: $submission instanceof HomeworkSubmission ? $submission->getBody() : '',
            revisionNumber: $submission instanceof HomeworkSubmission ? $submission->getRevisionNumber() : 0,
            writable: $writable,
            submitted: $submitted,
            submittedAtLocal: $submittedAtLocal,
            closedReason: $closedReason,
        );
    }

    private function hierarchyActive(HomeworkAssignment $assignment): bool
    {
        return InstitutionStatus::Active === $assignment->getInstitution()->getStatus()
            && ClassroomStatus::Active === $assignment->getClassroom()->getStatus()
            && AcademicYearStatus::Active === $assignment->getAcademicYear()->getStatus();
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function statusParts(?HomeworkSubmission $submission): array
    {
        if (!$submission instanceof HomeworkSubmission) {
            return ['none', 'Teslim edilmedi', 'Ödevi aç'];
        }
        if (HomeworkSubmissionStatus::Submitted === $submission->getStatus()) {
            return ['submitted', 'Gönderildi', 'Teslimimi gör'];
        }

        return ['draft', 'Taslak', 'Devam et'];
    }

    private function formatLocal(\DateTimeImmutable $utc): string
    {
        $local = UtcInstant::ensure($utc)->setTimezone(new \DateTimeZone(self::DISPLAY_TZ));
        $months = [
            1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
            7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
        ];
        $month = $months[(int) $local->format('n')];

        return $local->format('j').' '.$month.' '.$local->format('Y').' • '.$local->format('H.i');
    }
}

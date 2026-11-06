<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Dto\HomeworkTeacherAssignmentStats;
use App\Dto\HomeworkTeacherSubmissionDetail;
use App\Dto\HomeworkTeacherSubmissionRow;
use App\Entity\HomeworkAssignment;
use App\Entity\HomeworkRecipient;
use App\Entity\HomeworkSubmission;
use App\Entity\User;
use App\Enum\HomeworkSubmissionStatus;
use App\Time\UtcInstant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher read models for submitted homework. Never selects draft bodies.
 */
final class HomeworkTeacherSubmissionQuery
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HomeworkTeacherEvaluationScope $scope,
        private readonly HomeworkTeacherScope $teacherScope,
    ) {
    }

    public function statsFor(User $actor, HomeworkAssignment $assignment): ?HomeworkTeacherAssignmentStats
    {
        if (!$this->actorSeesAssignment($actor, $assignment)) {
            return null;
        }

        /** @var array{recipients: int|string, submitted: int|string, evaluated: int|string}|null $row */
        $row = $this->entityManager->createQueryBuilder()
            ->select(
                'COUNT(r.id) AS recipients',
                'COALESCE(SUM(CASE WHEN s.status = :submitted THEN 1 ELSE 0 END), 0) AS submitted',
                'COALESCE(SUM(CASE WHEN s.status = :submitted AND s.currentEvaluation IS NOT NULL THEN 1 ELSE 0 END), 0) AS evaluated',
            )
            ->from(HomeworkRecipient::class, 'r')
            ->leftJoin(
                HomeworkSubmission::class,
                's',
                'WITH',
                's.recipient = r AND s.status = :submitted',
            )
            ->andWhere('r.assignment = :assignment')
            ->setParameter('assignment', $assignment->getId(), 'uuid')
            ->setParameter('submitted', HomeworkSubmissionStatus::Submitted)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $row) {
            return new HomeworkTeacherAssignmentStats(0, 0, 0);
        }

        return new HomeworkTeacherAssignmentStats(
            (int) $row['recipients'],
            (int) $row['submitted'],
            (int) $row['evaluated'],
        );
    }

    /**
     * @return list<HomeworkTeacherSubmissionRow>|null
     */
    public function recipientRows(User $actor, HomeworkAssignment $assignment): ?array
    {
        if (!$this->actorSeesAssignment($actor, $assignment)) {
            return null;
        }

        /** @var list<array{
         *     recipientId: Uuid|string,
         *     firstName: string,
         *     lastName: string,
         *     submissionId: Uuid|string|null,
         *     submittedAt: mixed,
         *     evaluationId: Uuid|string|null,
         *     label: mixed
         * }> $rows
         */
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'r.id AS recipientId',
                'u.firstName AS firstName',
                'u.lastName AS lastName',
                's.id AS submissionId',
                's.submittedAt AS submittedAt',
                'e.id AS evaluationId',
                'e.label AS label',
            )
            ->from(HomeworkRecipient::class, 'r')
            ->innerJoin('r.studentUser', 'u')
            ->leftJoin(
                HomeworkSubmission::class,
                's',
                'WITH',
                's.recipient = r AND s.status = :submitted',
            )
            ->leftJoin('s.currentEvaluation', 'e')
            ->andWhere('r.assignment = :assignment')
            ->setParameter('assignment', $assignment->getId(), 'uuid')
            ->setParameter('submitted', HomeworkSubmissionStatus::Submitted)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $submissionId = self::optionalUuid($row['submissionId']);
            $hasSubmission = null !== $submissionId;
            $label = $row['label'] ?? null;
            $labelKey = null;
            $labelTr = null;
            if ($hasSubmission && null !== $label) {
                $rawLabel = $label instanceof \BackedEnum ? (string) $label->value : (string) $label;
                $labelKey = '' !== $rawLabel ? $rawLabel : null;
                $labelTr = match ($labelKey) {
                    'reviewed' => 'İncelendi',
                    'needs_rework' => 'Yeniden çalışılmalı',
                    default => null,
                };
            }
            $out[] = new HomeworkTeacherSubmissionRow(
                recipientId: self::uuidString($row['recipientId']),
                studentDisplayName: trim($row['firstName'].' '.$row['lastName']),
                stateKey: $hasSubmission ? (null === $labelKey ? 'submitted' : 'evaluated') : 'awaiting',
                stateLabel: $hasSubmission
                    ? (null === $labelTr ? 'Gönderildi' : $labelTr)
                    : 'Gönderilmedi',
                submissionId: $submissionId,
                submittedAtLocal: $hasSubmission && null !== $row['submittedAt']
                    ? UtcInstant::formatForUser(
                        $row['submittedAt'] instanceof \DateTimeInterface
                            ? $row['submittedAt']
                            : new \DateTimeImmutable((string) $row['submittedAt']),
                        'Europe/Istanbul',
                        'd.m.Y H:i',
                    )
                    : null,
                evaluationLabelKey: $labelKey,
                evaluationLabelTr: $labelTr,
                canInspect: $hasSubmission,
            );
        }

        return $out;
    }

    public function submittedDetail(User $actor, Uuid $submissionId): ?HomeworkTeacherSubmissionDetail
    {
        /** @var HomeworkSubmission|null $submission */
        $submission = $this->entityManager->createQueryBuilder()
            ->select('s', 'a', 'r', 'u', 'd', 'c', 'e')
            ->from(HomeworkSubmission::class, 's')
            ->innerJoin('s.assignment', 'a')
            ->innerJoin('s.recipient', 'r')
            ->innerJoin('s.studentUser', 'u')
            ->innerJoin('a.definition', 'd')
            ->innerJoin('a.classroom', 'c')
            ->leftJoin('s.currentEvaluation', 'e')
            ->andWhere('s.id = :id')
            ->andWhere('s.status = :submitted')
            ->setParameter('id', $submissionId, 'uuid')
            ->setParameter('submitted', HomeworkSubmissionStatus::Submitted)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$submission instanceof HomeworkSubmission) {
            return null;
        }
        if (!$this->scope->canManageSubmitted($actor, $submission, true)) {
            return null;
        }

        $evaluation = $submission->getCurrentEvaluation();
        $submittedAt = $submission->getSubmittedAt();
        if (null === $submittedAt) {
            return null;
        }

        return new HomeworkTeacherSubmissionDetail(
            submissionId: $submission->getId()->toRfc4122(),
            definitionId: $submission->getAssignment()->getDefinition()->getId()->toRfc4122(),
            assignmentId: $submission->getAssignment()->getId()->toRfc4122(),
            title: $submission->getAssignment()->getTitle(),
            description: $submission->getAssignment()->getDescription(),
            classroomName: $submission->getAssignment()->getClassroom()->getName(),
            studentDisplayName: trim(
                $submission->getStudentUser()->getFirstName().' '.$submission->getStudentUser()->getLastName(),
            ),
            submittedAtLocal: UtcInstant::formatForUser($submittedAt, 'Europe/Istanbul', 'd.m.Y H:i'),
            body: $submission->getBody(),
            currentEvaluationId: $evaluation?->getId()->toRfc4122(),
            evaluationLabelKey: $evaluation?->getLabel()->value,
            evaluationLabelTr: $evaluation?->getLabel()->labelTr(),
            feedback: $evaluation?->getFeedback(),
            evaluatedAtLocal: null === $evaluation
                ? null
                : UtcInstant::formatForUser($evaluation->getRecordedAt(), 'Europe/Istanbul', 'd.m.Y H:i'),
        );
    }

    private function actorSeesAssignment(User $actor, HomeworkAssignment $assignment): bool
    {
        if (!$this->scope->ownsAssignment($actor, $assignment)) {
            return false;
        }

        // Open scope: active classroom/year required for evaluate-related reads (§4.3).
        return null !== $this->teacherScope->findAssignment(
            $actor,
            $assignment->getClassroom()->getId(),
            true,
            false,
        );
    }

    private static function uuidString(Uuid|string $value): string
    {
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }

        return 16 === \strlen($value)
            ? Uuid::fromBinary($value)->toRfc4122()
            : Uuid::fromString($value)->toRfc4122();
    }

    private static function optionalUuid(Uuid|string|null $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return self::uuidString($value);
    }
}

<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\AssessmentItemScore;
use App\Entity\AssessmentManualGradeDecision;
use App\Entity\AssessmentResultActiveReleaseGuard;
use App\Entity\AssessmentResultRelease;
use App\Entity\AssessmentScoringRun;
use App\Enum\ScoringRunStatus;
use App\Exception\AssessmentScoringException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Protects scoring identity / completed-run immutability and append-only manual decisions.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class AssessmentScoringImmutabilityListener
{
    private const SCORING_RUN_MUTABLE_WHILE_OPEN = [
        'status',
        'rawPoints',
        'finalPoints',
        'maximumPoints',
        'percentage',
        'correctCount',
        'incorrectCount',
        'unansweredCount',
        'manualPendingCount',
        'completedAt',
        'updatedAt',
    ];

    private const ITEM_SCORE_MUTABLE_WHILE_OPEN = [
        'scoringMethod',
        'outcome',
        'awardedPoints',
        'penaltyPointsApplied',
        'manualPending',
        'evaluatorUser',
        'evaluatedAt',
        'reasonCode',
        'updatedAt',
    ];

    private const RELEASE_MUTABLE = [
        'status',
        'releasedBy',
        'releasedAt',
        'withdrawnBy',
        'withdrawnAt',
        'updatedAt',
    ];

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof AssessmentScoringRun
            && ScoringRunStatus::Processing !== $entity->getStatus()
        ) {
            throw AssessmentScoringException::invalidInput(
                'scoring_run create requires processing status.',
            );
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof AssessmentManualGradeDecision) {
            throw AssessmentScoringException::immutable();
        }

        if ($entity instanceof AssessmentScoringRun) {
            $oldStatus = $args->hasChangedField('status')
                ? $args->getOldValue('status')
                : $entity->getStatus();
            if ($oldStatus instanceof ScoringRunStatus
                && (ScoringRunStatus::Completed === $oldStatus || ScoringRunStatus::Failed === $oldStatus)
            ) {
                throw AssessmentScoringException::immutable();
            }

            foreach (array_keys($args->getEntityChangeSet()) as $field) {
                if (!\in_array($field, self::SCORING_RUN_MUTABLE_WHILE_OPEN, true)) {
                    throw AssessmentScoringException::immutable();
                }
            }

            return;
        }

        if ($entity instanceof AssessmentItemScore) {
            if (!$entity->getScoringRun()->getStatus()->allowsItemMutation()) {
                throw AssessmentScoringException::immutable();
            }
            foreach (array_keys($args->getEntityChangeSet()) as $field) {
                if (!\in_array($field, self::ITEM_SCORE_MUTABLE_WHILE_OPEN, true)) {
                    throw AssessmentScoringException::immutable();
                }
            }

            return;
        }

        if ($entity instanceof AssessmentResultRelease) {
            foreach (array_keys($args->getEntityChangeSet()) as $field) {
                if (!\in_array($field, self::RELEASE_MUTABLE, true)) {
                    throw AssessmentScoringException::immutable();
                }
            }
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof AssessmentScoringRun
            || $entity instanceof AssessmentItemScore
            || $entity instanceof AssessmentManualGradeDecision
            || $entity instanceof AssessmentResultRelease
            || $entity instanceof AssessmentResultActiveReleaseGuard
        ) {
            throw AssessmentScoringException::immutable();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\AssessmentResultActiveReviewPolicyGuard;
use App\Entity\AssessmentResultReviewPolicy;
use App\Enum\ResultReviewPolicyStatus;
use App\Exception\AssessmentResultReviewException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * ORM early-fail for review-policy identity / content immutability and append-only active rows.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class AssessmentResultReviewImmutabilityListener
{
    private const DRAFT_MUTABLE = [
        'availabilityMode',
        'scheduledAt',
        'showScoreSummary',
        'showItemOutcomes',
        'showStudentAnswer',
        'showCorrectAnswer',
        'showExplanation',
        'reasonCode',
        'policyHash',
        'status',
        'activatedBy',
        'activatedAt',
        'updatedAt',
    ];

    private const ACTIVE_MUTABLE = [
        'status',
        'supersededAt',
        'updatedAt',
    ];

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof AssessmentResultReviewPolicy
            && ResultReviewPolicyStatus::Draft !== $entity->getStatus()
        ) {
            throw AssessmentResultReviewException::invalidInput(
                'review_policy create requires draft status.',
            );
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof AssessmentResultReviewPolicy) {
            return;
        }

        $oldStatus = $args->hasChangedField('status')
            ? $args->getOldValue('status')
            : $entity->getStatus();
        if (\is_string($oldStatus)) {
            $oldStatus = ResultReviewPolicyStatus::tryFrom($oldStatus);
        }

        if ($oldStatus instanceof ResultReviewPolicyStatus
            && ResultReviewPolicyStatus::Superseded === $oldStatus
        ) {
            throw AssessmentResultReviewException::immutable();
        }

        if ($oldStatus instanceof ResultReviewPolicyStatus
            && ResultReviewPolicyStatus::Active === $oldStatus
        ) {
            foreach (array_keys($args->getEntityChangeSet()) as $field) {
                if (!\in_array($field, self::ACTIVE_MUTABLE, true)) {
                    throw AssessmentResultReviewException::immutable();
                }
            }

            return;
        }

        foreach (array_keys($args->getEntityChangeSet()) as $field) {
            if (!\in_array($field, self::DRAFT_MUTABLE, true)) {
                throw AssessmentResultReviewException::immutable();
            }
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof AssessmentResultActiveReviewPolicyGuard) {
            throw AssessmentResultReviewException::immutable();
        }

        if ($entity instanceof AssessmentResultReviewPolicy
            && !$entity->getStatus()->allowsDraftMutation()
        ) {
            throw AssessmentResultReviewException::immutable();
        }
    }
}

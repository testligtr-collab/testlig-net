<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentAttemptAnswer;
use App\Entity\AssessmentAttemptItem;
use App\Exception\AssessmentAttemptException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Protects attempt identity / lifecycle rules and full immutability of attempt items.
 * Answer ciphertext may update only while the ORM layer allows; DB triggers enforce in_progress.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class AssessmentAttemptImmutabilityListener
{
    private const ATTEMPT_MUTABLE = [
        'status',
        'submittedAt',
        'expiredAt',
        'cancelledAt',
        'cancelledBy',
        'cancellationReasonCode',
        'lastActivityAt',
        'updatedAt',
        // DB STORED generated; may appear in UoW after status transitions.
        'activeRecipientScopeId',
    ];

    private const ANSWER_MUTABLE = [
        'answerCiphertext',
        'answerNonce',
        'encryptionVersion',
        'clientRevision',
        'answeredAt',
        'updatedAt',
    ];

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof AssessmentAttemptItem) {
            throw AssessmentAttemptException::immutable();
        }

        if ($entity instanceof AssessmentAttempt) {
            foreach (array_keys($args->getEntityChangeSet()) as $field) {
                if (!\in_array($field, self::ATTEMPT_MUTABLE, true)) {
                    throw AssessmentAttemptException::immutable();
                }
            }

            return;
        }

        if ($entity instanceof AssessmentAttemptAnswer) {
            foreach (array_keys($args->getEntityChangeSet()) as $field) {
                if (!\in_array($field, self::ANSWER_MUTABLE, true)) {
                    throw AssessmentAttemptException::immutable();
                }
            }
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof AssessmentAttempt
            || $entity instanceof AssessmentAttemptItem
            || $entity instanceof AssessmentAttemptAnswer
        ) {
            throw AssessmentAttemptException::immutable();
        }
    }
}

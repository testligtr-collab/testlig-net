<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Enum\AssessmentDeliveryStatus;
use App\Exception\AssessmentDeliveryException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Protects delivery/recipient identity immutability after create / after draft.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class AssessmentDeliveryImmutabilityListener
{
    private const RECIPIENT_MUTABLE = [
        'status',
        'revokedAt',
        'revokedBy',
        'revocationReasonCode',
    ];

    private const DELIVERY_POST_DRAFT_MUTABLE = [
        'status',
        'activatedBy',
        'activatedAt',
        'closedBy',
        'closedAt',
        'cancelledBy',
        'cancelledAt',
        'cancellationReasonCode',
        'updatedAt',
    ];

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof AssessmentDeliveryRecipient) {
            foreach (array_keys($args->getEntityChangeSet()) as $field) {
                if (!\in_array($field, self::RECIPIENT_MUTABLE, true)) {
                    throw AssessmentDeliveryException::immutable();
                }
            }

            return;
        }

        if (!$entity instanceof AssessmentDelivery) {
            return;
        }

        $changeset = $args->getEntityChangeSet();
        $oldStatus = isset($changeset['status'])
            ? $changeset['status'][0]
            : $entity->getStatus();
        if ($oldStatus instanceof AssessmentDeliveryStatus
            && AssessmentDeliveryStatus::Draft === $oldStatus
        ) {
            // Draft may mutate window/content fields via manager; identity fields still blocked.
            foreach (array_keys($changeset) as $field) {
                if ($this->isDeliveryIdentityField($field)) {
                    throw AssessmentDeliveryException::immutable();
                }
            }

            return;
        }

        foreach (array_keys($changeset) as $field) {
            if (!\in_array($field, self::DELIVERY_POST_DRAFT_MUTABLE, true)) {
                throw AssessmentDeliveryException::immutable();
            }
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof AssessmentDeliveryRecipient) {
            throw AssessmentDeliveryException::immutable();
        }
        // Deliveries are not hard-deleted in application paths; test cleanup uses DBAL DELETE.
    }

    private function isDeliveryIdentityField(string $field): bool
    {
        return \in_array($field, [
            'institution',
            'assessment',
            'assessmentPublication',
            'publicationNumber',
            'audienceType',
            'classroom',
            'studentMembership',
            'createdBy',
            'createdAt',
        ], true);
    }
}

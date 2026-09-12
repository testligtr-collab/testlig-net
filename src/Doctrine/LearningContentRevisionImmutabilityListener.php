<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\LearningContentPublication;
use App\Entity\LearningContentRevision;
use App\Exception\LearningContentException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Learning content revision: unsealed rows may update; sealed rows are immutable.
 * Seal transition (isSealed false→true + sealedAt) is allowed once.
 * Publications are fully append-only.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class LearningContentRevisionImmutabilityListener
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof LearningContentRevision) {
            $changeset = $args->getEntityChangeSet();
            $wasSealed = \array_key_exists('isSealed', $changeset)
                ? (bool) $changeset['isSealed'][0]
                : $entity->isSealed();

            if ($wasSealed) {
                throw LearningContentException::immutable();
            }

            if (\array_key_exists('isSealed', $changeset)) {
                $becomesSealed = false === $changeset['isSealed'][0] && true === $changeset['isSealed'][1];
                if (!$becomesSealed) {
                    throw LearningContentException::immutable();
                }
            }

            if (\array_key_exists('id', $changeset)
                || \array_key_exists('content', $changeset)
                || \array_key_exists('revisionNumber', $changeset)
                || \array_key_exists('createdBy', $changeset)
                || \array_key_exists('createdAt', $changeset)
            ) {
                throw LearningContentException::immutable();
            }

            return;
        }

        if ($entity instanceof LearningContentPublication) {
            throw LearningContentException::immutable();
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof LearningContentRevision
            || $entity instanceof LearningContentPublication
        ) {
            throw LearningContentException::immutable();
        }
    }
}

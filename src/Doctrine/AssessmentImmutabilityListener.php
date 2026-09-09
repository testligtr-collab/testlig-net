<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\AssessmentItem;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentRevision;
use App\Entity\AssessmentSection;
use App\Exception\AssessmentException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Append-only protection for assessment revision graph and publications.
 * AssessmentRevision may only transition isSealed false → true.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class AssessmentImmutabilityListener
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof AssessmentRevision) {
            $changeset = $args->getEntityChangeSet();
            $allowed = isset($changeset['isSealed'])
                && false === $changeset['isSealed'][0]
                && true === $changeset['isSealed'][1]
                && 1 === \count($changeset);
            if (!$allowed) {
                throw AssessmentException::immutable();
            }

            return;
        }

        if ($this->isFullyImmutable($entity)) {
            throw AssessmentException::immutable();
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof AssessmentRevision || $this->isFullyImmutable($entity)) {
            throw AssessmentException::immutable();
        }
    }

    private function isFullyImmutable(object $entity): bool
    {
        return $entity instanceof AssessmentSection
            || $entity instanceof AssessmentItem
            || $entity instanceof AssessmentPublication;
    }
}

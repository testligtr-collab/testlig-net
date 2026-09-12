<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\LearningContentOutcomeAlignment;
use App\Entity\LearningContentRevisionAsset;
use App\Entity\LearningContentRevisionPrimaryAlignmentGuard;
use App\Exception\LearningContentException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Blocks mutation of revision children once the parent revision is sealed.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class LearningContentRevisionAssetImmutabilityListener
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof LearningContentRevisionAsset && $entity->getRevision()->isSealed()) {
            throw LearningContentException::immutable();
        }
        if ($entity instanceof LearningContentOutcomeAlignment && $entity->getRevision()->isSealed()) {
            throw LearningContentException::immutable();
        }
        if ($entity instanceof LearningContentRevisionPrimaryAlignmentGuard && $entity->getRevision()->isSealed()) {
            throw LearningContentException::immutable();
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof LearningContentRevisionAsset && $entity->getRevision()->isSealed()) {
            throw LearningContentException::immutable();
        }
        if ($entity instanceof LearningContentOutcomeAlignment && $entity->getRevision()->isSealed()) {
            throw LearningContentException::immutable();
        }
        if ($entity instanceof LearningContentRevisionPrimaryAlignmentGuard && $entity->getRevision()->isSealed()) {
            throw LearningContentException::immutable();
        }
    }
}

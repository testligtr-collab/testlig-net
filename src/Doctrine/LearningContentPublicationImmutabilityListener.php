<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\LearningContentPublication;
use App\Exception\LearningContentException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Append-only protection for learning content publications.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class LearningContentPublicationImmutabilityListener
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if ($args->getObject() instanceof LearningContentPublication) {
            throw LearningContentException::immutable();
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        if ($args->getObject() instanceof LearningContentPublication) {
            throw LearningContentException::immutable();
        }
    }
}

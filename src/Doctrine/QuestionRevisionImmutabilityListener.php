<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\QuestionAnswerKey;
use App\Entity\QuestionRevision;
use App\Entity\QuestionRevisionAlignment;
use App\Entity\QuestionRevisionOption;
use App\Exception\QuestionException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Append-only protection for question revision content and related rows.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class QuestionRevisionImmutabilityListener
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if ($this->isImmutable($args->getObject())) {
            throw QuestionException::immutable();
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        if ($this->isImmutable($args->getObject())) {
            throw QuestionException::immutable();
        }
    }

    private function isImmutable(object $entity): bool
    {
        return $entity instanceof QuestionRevision
            || $entity instanceof QuestionRevisionOption
            || $entity instanceof QuestionAnswerKey
            || $entity instanceof QuestionRevisionAlignment;
    }
}

<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\SecurityAuditEvent;
use App\Exception\SecurityAuditImmutableException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Blocks update/delete of SecurityAuditEvent through the ORM unit of work.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class SecurityAuditImmutabilitySubscriber
{
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        if ($args->getObject() instanceof SecurityAuditEvent) {
            throw SecurityAuditImmutableException::updateForbidden();
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        if ($args->getObject() instanceof SecurityAuditEvent) {
            throw SecurityAuditImmutableException::deleteForbidden();
        }
    }
}

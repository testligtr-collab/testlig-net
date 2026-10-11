<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Institution;
use App\Entity\InstitutionApplication;
use App\Entity\InstitutionMembership;
use App\Entity\SecurityAuditEvent;
use App\Enum\InstitutionMembershipRole;
use App\Enum\SecurityAuditAction;
use Doctrine\ORM\Event\PostFlushEventArgs;

/**
 * Records the institution create flush that happens before the application link.
 */
final class InstitutionApplicationLinkFlushObserver
{
    private bool $sawInstitution = false;

    private bool $sawOwnerMembership = false;

    private bool $sawCreatedAudit = false;

    private bool $sawMemberAudit = false;

    private bool $sawLink = false;

    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($args->getObjectManager()->getUnitOfWork()->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof Institution) {
                    $this->sawInstitution = true;
                }
                if ($entity instanceof InstitutionMembership && InstitutionMembershipRole::Owner === $entity->getRole()) {
                    $this->sawOwnerMembership = true;
                }
                if ($entity instanceof SecurityAuditEvent && SecurityAuditAction::InstitutionCreated === $entity->getAction()) {
                    $this->sawCreatedAudit = true;
                }
                if ($entity instanceof SecurityAuditEvent && SecurityAuditAction::InstitutionMemberAdded === $entity->getAction()) {
                    $this->sawMemberAudit = true;
                }
                if ($entity instanceof InstitutionApplication && null !== $entity->getCreatedInstitution()) {
                    $this->sawLink = true;
                }
            }
        }
    }

    public function sawWritesBeforeLink(): bool
    {
        return $this->sawInstitution
            && $this->sawOwnerMembership
            && $this->sawCreatedAudit
            && $this->sawMemberAudit
            && !$this->sawLink;
    }
}

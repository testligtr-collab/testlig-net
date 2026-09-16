<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminAuditEventListItem;
use App\Dto\AdminPagedResult;
use App\Entity\SecurityAuditEvent;
use App\Service\SecurityAuditMetadataSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * SUPER_ADMIN-only security audit listing with re-sanitized metadata.
 */
final class AdminAuditReadModel
{
    public function __construct(
        private readonly AdminActorGuard $actorGuard,
        private readonly EntityManagerInterface $em,
        private readonly SecurityAuditMetadataSanitizer $sanitizer,
    ) {
    }

    /**
     * @return AdminPagedResult<AdminAuditEventListItem>
     */
    public function listEvents(Uuid $actorId, int $page = 1, int $pageSize = AdminPagination::DEFAULT_PAGE_SIZE): AdminPagedResult
    {
        $this->actorGuard->requireAuditView($actorId);
        $page = AdminPagination::normalizePage($page);
        $pageSize = AdminPagination::normalizePageSize($pageSize);

        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(SecurityAuditEvent::class, 'e')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<SecurityAuditEvent> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('e')
            ->from(SecurityAuditEvent::class, 'e')
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $event) {
            $items[] = $this->toItem($event);
        }

        return new AdminPagedResult($items, $page, $pageSize, $total);
    }

    private function toItem(SecurityAuditEvent $event): AdminAuditEventListItem
    {
        try {
            /** @var array<string, bool|int|string|null> $metadata */
            $metadata = $this->sanitizer->sanitize($event->getMetadata());
        } catch (\Throwable) {
            $metadata = [];
        }

        // Never surface hashes even if somehow present under an unexpected key.
        unset($metadata['ip_hash'], $metadata['user_agent_hash'], $metadata['payload_hash'], $metadata['signature_fingerprint']);

        return new AdminAuditEventListItem(
            eventId: $event->getId(),
            occurredAt: $event->getOccurredAt(),
            action: $event->getAction(),
            actorType: $event->getActorType(),
            outcome: $event->getOutcome(),
            actorUserId: $event->getActorUser()?->getId(),
            subjectUserId: $event->getSubjectUser()?->getId(),
            metadata: $metadata,
        );
    }
}

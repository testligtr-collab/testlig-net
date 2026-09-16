<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use Symfony\Component\Uid\Uuid;

/**
 * Sanitized security audit list row (allowlisted metadata only; no email/ip/ua/hash).
 */
final class AdminAuditEventListItem
{
    /**
     * @param array<string, bool|int|string|null> $metadata
     */
    public function __construct(
        public readonly Uuid $eventId,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly SecurityAuditAction $action,
        public readonly SecurityAuditActorType $actorType,
        public readonly SecurityAuditOutcome $outcome,
        public readonly ?Uuid $actorUserId,
        public readonly ?Uuid $subjectUserId,
        public readonly array $metadata,
    ) {
    }
}

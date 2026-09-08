<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;

/**
 * Typed context for creating a security audit event. Controllers must not build entities.
 *
 * @phpstan-type MetadataMap array<string, scalar|list<scalar>|null>
 */
final class SecurityAuditContext
{
    /**
     * @param MetadataMap $metadata
     */
    public function __construct(
        public readonly SecurityAuditAction $action,
        public readonly SecurityAuditActorType $actorType,
        public readonly SecurityAuditOutcome $outcome,
        public readonly ?User $actorUser = null,
        public readonly ?User $subjectUser = null,
        public readonly array $metadata = [],
        public readonly ?string $correlationId = null,
        public readonly bool $captureRequestHashes = true,
    ) {
    }
}

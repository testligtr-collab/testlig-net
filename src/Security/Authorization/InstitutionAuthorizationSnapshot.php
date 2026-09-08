<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\InstitutionStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for an institution. Independent of Doctrine identity map.
 */
final readonly class InstitutionAuthorizationSnapshot
{
    public function __construct(
        public Uuid $id,
        public InstitutionStatus $status,
    ) {
    }

    public function isActive(): bool
    {
        return InstitutionStatus::Active === $this->status;
    }
}

<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\InstitutionStatus;
use App\Enum\InstitutionType;
use Symfony\Component\Uid\Uuid;

final class AdminInstitutionListItemView
{
    public function __construct(
        public readonly Uuid $id,
        public readonly string $shortRef,
        public readonly string $name,
        public readonly string $slug,
        public readonly InstitutionType $type,
        public readonly InstitutionStatus $status,
        public readonly string $locale,
        public readonly string $timezone,
        public readonly int $activeMemberCount,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }
}

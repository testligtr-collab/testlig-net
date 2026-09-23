<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\CatalogUnit;
use App\Enum\CatalogPublicationStatus;

final class CatalogUnitListItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly CatalogPublicationStatus $status,
        public readonly int $position,
    ) {
    }

    public static function fromEntity(CatalogUnit $unit): self
    {
        return new self(
            $unit->getId()->toRfc4122(),
            $unit->getName(),
            $unit->getSlug(),
            $unit->getDescription(),
            $unit->getStatus(),
            $unit->getPosition(),
        );
    }
}

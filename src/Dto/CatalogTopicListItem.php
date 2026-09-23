<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\CatalogTopic;
use App\Enum\CatalogPublicationStatus;

final class CatalogTopicListItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $summary,
        public readonly CatalogPublicationStatus $status,
        public readonly int $position,
        public readonly ?int $estimatedMinutes,
    ) {
    }

    public static function fromEntity(CatalogTopic $topic): self
    {
        return new self(
            $topic->getId()->toRfc4122(),
            $topic->getName(),
            $topic->getSlug(),
            $topic->getSummary(),
            $topic->getStatus(),
            $topic->getPosition(),
            $topic->getEstimatedMinutes(),
        );
    }
}

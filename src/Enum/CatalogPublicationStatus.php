<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Publication lifecycle for the student-facing course catalog (not Stage 2.7 curriculum programs).
 */
enum CatalogPublicationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Archived],
            self::Published => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function isVisibleToStudents(): bool
    {
        return self::Published === $this;
    }

    public function labelTr(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::Published => 'Yayımlanmış',
            self::Archived => 'Arşiv',
        };
    }
}

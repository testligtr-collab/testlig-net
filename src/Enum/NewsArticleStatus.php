<?php

declare(strict_types=1);

namespace App\Enum;

enum NewsArticleStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::InReview, self::Archived],
            self::InReview => [self::Draft, self::Published],
            self::Published => [self::Archived, self::Draft],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function labelTr(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::InReview => 'İncelemede',
            self::Published => 'Yayında',
            self::Archived => 'Arşivlenmiş',
        };
    }
}

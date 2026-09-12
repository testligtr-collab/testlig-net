<?php

declare(strict_types=1);

namespace App\Enum;

enum StoredMediaAssetStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Quarantined = 'quarantined';
    case Archived = 'archived';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Ready, self::Quarantined],
            self::Ready => [self::Quarantined, self::Archived],
            self::Quarantined => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }
}

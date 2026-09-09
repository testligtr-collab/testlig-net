<?php

declare(strict_types=1);

namespace App\Enum;

enum CurriculumStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published],
            self::Published => [self::Retired],
            self::Retired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function allowsStructuralMutation(): bool
    {
        return self::Draft === $this;
    }

    public function isPublished(): bool
    {
        return self::Published === $this;
    }
}

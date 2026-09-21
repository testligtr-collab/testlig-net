<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle for teacher / institution onboarding applications (Stage 2.22.3).
 *
 * Pending ≠ privilege. Approved ≠ automatic ROLE_TEACHER / Institution / membership.
 */
enum OnboardingApplicationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Superseded = 'superseded';

    public function isOpen(): bool
    {
        return self::Pending === $this;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Approved, self::Rejected, self::Withdrawn, self::Superseded => true,
            self::Pending => false,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Withdrawn, self::Superseded],
            self::Approved, self::Rejected, self::Withdrawn, self::Superseded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }

    public function allowsResubmit(): bool
    {
        return match ($this) {
            self::Rejected, self::Withdrawn, self::Superseded => true,
            self::Pending, self::Approved => false,
        };
    }
}

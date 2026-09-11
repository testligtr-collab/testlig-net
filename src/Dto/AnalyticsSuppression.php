<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\AnalyticsSuppressionReason;

/**
 * Suppression flag + reason for aggregate analytics fields.
 */
final class AnalyticsSuppression
{
    public function __construct(
        private readonly bool $suppressed,
        private readonly ?AnalyticsSuppressionReason $reason = null,
    ) {
        if ($suppressed && null === $reason) {
            throw new \InvalidArgumentException('Suppressed analytics require a reason.');
        }
        if (!$suppressed && null !== $reason) {
            throw new \InvalidArgumentException('Non-suppressed analytics must not carry a reason.');
        }
    }

    public static function none(): self
    {
        return new self(false, null);
    }

    public static function of(AnalyticsSuppressionReason $reason): self
    {
        return new self(true, $reason);
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed;
    }

    public function getReason(): ?AnalyticsSuppressionReason
    {
        return $this->reason;
    }

    /**
     * @return array{suppressed: bool, suppressionReason?: string}
     */
    public function toArray(): array
    {
        $out = ['suppressed' => $this->suppressed];
        if ($this->suppressed && null !== $this->reason) {
            $out['suppressionReason'] = $this->reason->value;
        }

        return $out;
    }
}

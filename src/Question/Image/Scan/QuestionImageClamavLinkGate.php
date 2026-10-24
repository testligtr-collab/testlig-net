<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

use Psr\Clock\ClockInterface;

/**
 * Opens the question-screen connection check only while both settings allow it.
 *
 * The expiry closes the form by itself. It does not restore environment files.
 */
final class QuestionImageClamavLinkGate
{
    public const MAX_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly bool $enabled,
        private readonly string $expiresAt,
        private readonly ClockInterface $clock,
    ) {
    }

    public function isOpen(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,10})$/', $this->expiresAt)) {
            return false;
        }
        $remaining = (int) $this->expiresAt - $this->clock->now()->getTimestamp();

        return $remaining > 0 && $remaining <= self::MAX_WINDOW_SECONDS;
    }
}

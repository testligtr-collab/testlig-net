<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Clock\ClockInterface;

/**
 * Opens the web identity diagnostic only while both settings allow it.
 *
 * WEB_RUNTIME_IDENTITY_ENABLED must be on and WEB_RUNTIME_IDENTITY_EXPIRES_AT
 * must be a strict UTC Unix second strictly after now and at most 15 minutes
 * ahead. The decision is made on every call. This gate does not restore env
 * files; a later measurement still has to put the previous settings back.
 */
final class WebRuntimeIdentityGate
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

        $expires = $this->expiresAtUnix();
        if (null === $expires) {
            return false;
        }

        $remaining = $expires - $this->clock->now()->getTimestamp();

        return $remaining > 0 && $remaining <= self::MAX_WINDOW_SECONDS;
    }

    private function expiresAtUnix(): ?int
    {
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,10})$/', $this->expiresAt)) {
            return null;
        }

        return (int) $this->expiresAt;
    }
}

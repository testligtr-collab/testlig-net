<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether the configured mailer can deliver real outbound messages.
 *
 * null:// (and empty) DSNs discard mail silently — never present that as success in prod.
 * Functional tests keep null://null + MessageLogger; APP_ENV=test is treated as deliverable.
 */
final class OutboundMailCapability
{
    public function __construct(
        #[Autowire('%env(MAILER_DSN)%')]
        private readonly string $mailerDsn,
        #[Autowire('%env(APP_ENV)%')]
        private readonly string $appEnv,
    ) {
    }

    public function canDeliver(): bool
    {
        if ('test' === $this->appEnv) {
            return true;
        }

        $scheme = $this->scheme($this->mailerDsn);

        return '' !== $scheme && 'null' !== $scheme;
    }

    /**
     * Operator-facing hint (safe to log / show to admins — not a secret value).
     */
    public function missingConfigurationKey(): string
    {
        return 'MAILER_DSN';
    }

    private function scheme(string $dsn): string
    {
        $trimmed = trim($dsn);
        if ('' === $trimmed) {
            return '';
        }

        $scheme = parse_url($trimmed, \PHP_URL_SCHEME);

        return \is_string($scheme) ? strtolower($scheme) : '';
    }
}

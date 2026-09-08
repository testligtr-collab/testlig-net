<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds opaque rate-limiter keys without storing raw e-mail addresses.
 */
final class RateLimitKeyHasher
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {
    }

    public function hashEmail(string $normalizedEmail): string
    {
        return hash_hmac('sha256', $normalizedEmail, $this->appSecret);
    }
}

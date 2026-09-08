<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * HMAC helper for audit request fingerprints. Uses AUDIT_HASH_KEY, never APP_SECRET alone as the only key material.
 */
final class SecurityAuditHashGenerator
{
    public function __construct(
        #[Autowire('%env(AUDIT_HASH_KEY)%')]
        private readonly string $auditHashKey,
    ) {
    }

    public function hashIp(?string $ip): ?string
    {
        if (null === $ip || '' === trim($ip)) {
            return null;
        }

        return hash_hmac('sha256', 'ip:'.trim($ip), $this->auditHashKey);
    }

    public function hashUserAgent(?string $userAgent): ?string
    {
        if (null === $userAgent || '' === trim($userAgent)) {
            return null;
        }

        return hash_hmac('sha256', 'ua:'.trim($userAgent), $this->auditHashKey);
    }
}

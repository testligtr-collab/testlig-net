<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Enforcing page policy. Nonce covers the AssetMapper import map only.
 * The remote es-module-shims host is not allowed; the polyfill is disabled.
 */
final class ContentSecurityPolicy
{
    public static function header(string $nonce, bool $upgradeInsecureRequests): string
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            throw new \InvalidArgumentException('CSP nonce must be 32 hex characters.');
        }

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self' 'nonce-".$nonce."'",
            "style-src 'self'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            'frame-src https://www.youtube-nocookie.com https://player.vimeo.com',
            "media-src 'self'",
        ];
        if ($upgradeInsecureRequests) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}

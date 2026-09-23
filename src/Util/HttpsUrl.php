<?php

declare(strict_types=1);

namespace App\Util;

/**
 * HTTPS URL checks that accept official MEB URLs with Unicode path segments (e.g. DÖP.pdf).
 * PHP filter_var(FILTER_VALIDATE_URL) rejects many valid UTF-8 URLs.
 */
final class HttpsUrl
{
    public static function isValid(string $url, int $maxLength): bool
    {
        $url = trim($url);
        if ('' === $url || \strlen($url) > $maxLength) {
            return false;
        }
        if (!str_starts_with(strtolower($url), 'https://')) {
            return false;
        }
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if ('https' !== $scheme || '' === $host) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9.-]+$/', $host);
    }
}

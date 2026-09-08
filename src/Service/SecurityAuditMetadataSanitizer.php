<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\SecurityAuditMetadataException;

/**
 * Strict allowlist metadata sanitizer for security audit events.
 */
final class SecurityAuditMetadataSanitizer
{
    /**
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'reason',
        'source',
        'previous_roles',
        'new_roles',
        'previous_status',
        'new_status',
        'bootstrap',
        'institution_type',
        'institution_id',
        'membership_role',
        'previous_membership_role',
        'new_membership_role',
        'reason_code',
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN_NORMALIZED = [
        'password',
        'plainpassword',
        'plain_password',
        'token',
        'secrettoken',
        'secret',
        'authorization',
        'cookie',
        'session',
        'sessionid',
        'jwt',
        'apikey',
        'api_key',
        'email',
        'ip',
        'ipaddress',
        'useragent',
        'user_agent',
        'databaseurl',
        'database_url',
        'requestbody',
        'request_body',
        'form',
        'formcontent',
    ];

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return array<string, bool|float|int|string|list<bool|float|int|string>|null>
     */
    public function sanitize(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (!\is_string($key) || '' === $key) {
                throw SecurityAuditMetadataException::forbiddenKey((string) $key);
            }

            $normalizedKey = $this->normalizeKey($key);
            if ($this->isForbidden($normalizedKey)) {
                throw SecurityAuditMetadataException::forbiddenKey($key);
            }

            if (!\in_array($normalizedKey, self::ALLOWED_KEYS, true)) {
                throw SecurityAuditMetadataException::forbiddenKey($key);
            }

            $clean[$normalizedKey] = $this->normalizeValue($normalizedKey, $value);
        }

        return $clean;
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));

        return str_replace(['-', ' '], '_', $key);
    }

    private function isForbidden(string $normalizedKey): bool
    {
        $compact = str_replace('_', '', $normalizedKey);
        foreach (self::FORBIDDEN_NORMALIZED as $forbidden) {
            $forbiddenCompact = str_replace('_', '', $forbidden);
            if ($normalizedKey === $forbidden || $compact === $forbiddenCompact) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool|float|int|string|list<bool|float|int|string>|null
     */
    private function normalizeValue(string $key, mixed $value): mixed
    {
        if (null === $value || \is_bool($value) || \is_int($value) || \is_float($value) || \is_string($value)) {
            return $value;
        }

        if (\is_array($value)) {
            /** @var list<bool|float|int|string> $list */
            $list = [];
            foreach ($value as $item) {
                if (\is_bool($item) || \is_int($item) || \is_float($item) || \is_string($item)) {
                    $list[] = $item;
                    continue;
                }
                throw SecurityAuditMetadataException::unsupportedValue($key);
            }

            return $list;
        }

        throw SecurityAuditMetadataException::unsupportedValue($key);
    }
}

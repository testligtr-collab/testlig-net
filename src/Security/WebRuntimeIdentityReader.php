<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Reports the effective identity of the PHP process serving this request.
 *
 * cli and cli-server are not web evidence. A nonce only correlates the response
 * with the POST body; it is not a CSRF token.
 */
class WebRuntimeIdentityReader
{
    public const GROUP_LIMIT = 64;

    public function __construct(
        private readonly WebRuntimeIdentityProbe $probe,
    ) {
    }

    /**
     * @return array{nonce: string, euid: int, egid: int, groups: list<int>}|array{nonce: string, status: 'unresolved'}
     */
    public function read(string $nonce): array
    {
        if ('cli' === $this->probe->sapi() || 'cli-server' === $this->probe->sapi() || !$this->probe->available()) {
            return $this->unresolved($nonce);
        }

        try {
            $credentials = $this->probe->credentials();
        } catch (\Throwable) {
            return $this->unresolved($nonce);
        }

        $valid = $this->validCredentials($credentials);
        if (null === $valid) {
            return $this->unresolved($nonce);
        }

        return [
            'nonce' => $nonce,
            'euid' => $valid['euid'],
            'egid' => $valid['egid'],
            'groups' => $valid['groups'],
        ];
    }

    /**
     * @return array{nonce: string, status: 'unresolved'}
     */
    private function unresolved(string $nonce): array
    {
        return [
            'nonce' => $nonce,
            'status' => 'unresolved',
        ];
    }

    /**
     * @return array{euid: int, egid: int, groups: list<int>}|null
     */
    private function validCredentials(mixed $credentials): ?array
    {
        if (!\is_array($credentials)) {
            return null;
        }

        $euid = $credentials['euid'] ?? null;
        $egid = $credentials['egid'] ?? null;
        $groups = $credentials['groups'] ?? null;
        if (!\is_int($euid) || $euid < 0 || !\is_int($egid) || $egid < 0 || !\is_array($groups) || !array_is_list($groups)) {
            return null;
        }
        if (\count($groups) > self::GROUP_LIMIT) {
            return null;
        }

        $normalized = [];
        foreach ($groups as $group) {
            if (!\is_int($group) || $group < 0) {
                return null;
            }
            $normalized[] = $group;
        }

        return [
            'euid' => $euid,
            'egid' => $egid,
            'groups' => $normalized,
        ];
    }
}

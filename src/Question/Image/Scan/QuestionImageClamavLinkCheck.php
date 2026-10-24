<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

use App\Security\WebRuntimeIdentityProbe;

/**
 * The PHP process serving this POST checks its own POSIX identity, then pings clamd.
 *
 * The account, socket path, and group id are not taken from the client.
 */
final class QuestionImageClamavLinkCheck
{
    public const EXPECTED_UID = 5002;
    public const EXPECTED_GID = 5002;

    public function __construct(
        private readonly WebRuntimeIdentityProbe $probe,
        private readonly QuestionImageClamavLinkTransport $transport,
        private readonly string $groupGid,
    ) {
    }

    /**
     * @return array{identity: string, connection: string, version: string}
     */
    public function run(): array
    {
        if (!$this->identityMatches()) {
            return [
                'identity' => 'no',
                'connection' => 'not_attempted',
                'version' => 'not_attempted',
            ];
        }
        try {
            $pong = $this->transport->ping();
        } catch (\Throwable) {
            return [
                'identity' => 'yes',
                'connection' => 'no',
                'version' => 'not_attempted',
            ];
        }
        if ("PONG\n" !== $pong) {
            return [
                'identity' => 'yes',
                'connection' => 'no',
                'version' => 'not_attempted',
            ];
        }
        try {
            $version = $this->transport->version();
        } catch (\Throwable) {
            return [
                'identity' => 'yes',
                'connection' => 'yes',
                'version' => 'no',
            ];
        }
        $accepted = 1 === preg_match('/^ClamAV [0-9]+(?:\.[0-9]+){1,3}\/(0|[1-9][0-9]*)\/[A-Za-z]{3} [A-Za-z]{3} [ 0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} [0-9]{4}\n$/', $version);

        return [
            'identity' => 'yes',
            'connection' => 'yes',
            'version' => $accepted ? 'yes' : 'no',
        ];
    }

    /**
     * @param array{identity: string, connection: string, version: string} $result
     */
    public function message(array $result): string
    {
        if ('yes' !== $result['identity']) {
            return 'Kimlik eşleşmedi. Bağlantı denenmedi.';
        }
        if ('yes' !== $result['connection']) {
            return 'Kimlik eşleşti. Bağlantı kurulamadı.';
        }
        if ('yes' !== $result['version']) {
            return 'Kimlik eşleşti. Bağlantı var. Sürüm doğrulanmadı.';
        }

        return 'Kimlik eşleşti. Bağlantı var. Sürüm doğrulandı.';
    }

    private function identityMatches(): bool
    {
        if ('cli' === $this->probe->sapi() || 'cli-server' === $this->probe->sapi() || !$this->probe->available()) {
            return false;
        }
        if (1 !== preg_match('/^[1-9][0-9]*$/', $this->groupGid)) {
            return false;
        }
        $gid = (int) $this->groupGid;
        if (self::EXPECTED_GID === $gid || (string) $gid !== $this->groupGid) {
            return false;
        }
        try {
            $credentials = $this->probe->credentials();
        } catch (\Throwable) {
            return false;
        }
        if (!\is_array($credentials)) {
            return false;
        }
        $euid = $credentials['euid'] ?? null;
        $egid = $credentials['egid'] ?? null;
        $groups = $credentials['groups'] ?? null;
        if (self::EXPECTED_UID !== $euid || self::EXPECTED_GID !== $egid || !\is_array($groups) || !array_is_list($groups)) {
            return false;
        }
        $normalized = [];
        foreach ($groups as $group) {
            if (!\is_int($group) || $group < 0) {
                return false;
            }
            $normalized[$group] = $group;
        }
        $expected = [self::EXPECTED_GID => self::EXPECTED_GID, $gid => $gid];
        ksort($normalized);
        ksort($expected);

        return $normalized === $expected;
    }
}

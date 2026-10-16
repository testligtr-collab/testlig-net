<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Reads effective POSIX credentials. Names, paths, and the environment are not read.
 */
final class PosixWebRuntimeIdentityProbe implements WebRuntimeIdentityProbe
{
    public function sapi(): string
    {
        return \PHP_SAPI;
    }

    public function available(): bool
    {
        return \function_exists('posix_geteuid')
            && \function_exists('posix_getegid')
            && \function_exists('posix_getgroups');
    }

    public function credentials(): mixed
    {
        if (!$this->available()) {
            return null;
        }

        $euid = $this->call('posix_geteuid');
        $egid = $this->call('posix_getegid');
        $groups = $this->call('posix_getgroups');
        if (!\is_int($euid) || !\is_int($egid) || !\is_array($groups)) {
            return null;
        }

        $normalized = [];
        foreach ($groups as $group) {
            if (!\is_int($group)) {
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

    private function call(string $function): mixed
    {
        if (!\is_callable($function)) {
            return null;
        }

        return $function();
    }
}

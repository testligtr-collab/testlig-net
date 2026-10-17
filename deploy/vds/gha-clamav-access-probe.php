<?php

declare(strict_types=1);

/**
 * Read-only clamd PING and VERSION. Each command has its own local Unix connection.
 * A short write that makes progress is finished on that same connection.
 * A zero-length write, an unfinished frame, and a timeout are not retried.
 */
final class ClamavAccessProbeClient
{
    public const TIMEOUT_SECONDS = 5.0;

    /**
     * @param callable(float): mixed $connect
     */
    public static function report(callable $connect, float $timeoutSeconds = self::TIMEOUT_SECONDS): string
    {
        $ping = self::command($connect, "nPING\n", $timeoutSeconds, 32);
        $version = self::command($connect, "nVERSION\n", $timeoutSeconds, 256);

        return 'app_ping='.self::pingResult($ping)."\n".'app_version_daily='.self::versionResult($version)."\n";
    }

    /**
     * @param resource $socket
     */
    public static function writeAll($socket, string $bytes, float $deadline): string
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $wait = self::wait($socket, $deadline, true);
            if ('ok' !== $wait) {
                return $wait;
            }
            $written = fwrite($socket, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                return feof($socket) ? 'eof' : 'partial';
            }
            $offset += $written;
        }

        return 'ok';
    }

    /**
     * @param callable(float): mixed $connect
     */
    private static function command(callable $connect, string $command, float $timeoutSeconds, int $maxBytes): string
    {
        $socket = $connect($timeoutSeconds);
        if (!is_resource($socket)) {
            return 'unavailable';
        }
        stream_set_blocking($socket, false);
        $deadline = microtime(true) + $timeoutSeconds;
        try {
            $written = self::writeAll($socket, $command, $deadline);
            if ('ok' !== $written) {
                return $written;
            }

            return self::readFrame($socket, $deadline, $maxBytes);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     */
    private static function readFrame($socket, float $deadline, int $maxBytes): string
    {
        $buffer = '';
        while (!str_contains($buffer, "\n")) {
            if (strlen($buffer) >= $maxBytes) {
                return 'partial';
            }
            $wait = self::wait($socket, $deadline, false);
            if ('ok' !== $wait) {
                return $wait;
            }
            $piece = fread($socket, 128);
            if (!is_string($piece) || '' === $piece) {
                if (feof($socket)) {
                    return '' === $buffer ? 'eof' : 'partial';
                }
                continue;
            }
            $buffer .= $piece;
        }
        $newline = strpos($buffer, "\n");
        if (!is_int($newline) || $newline !== strlen($buffer) - 1 || strlen($buffer) > $maxBytes) {
            return 'partial';
        }

        return $buffer;
    }

    /**
     * @param resource $socket
     */
    private static function wait($socket, float $deadline, bool $write): string
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            return 'timed_out';
        }
        $seconds = (int) floor($remaining);
        $microseconds = (int) (($remaining - $seconds) * 1000000);
        $read = $write ? [] : [$socket];
        $writeSet = $write ? [$socket] : [];
        $except = null;
        $selected = stream_select($read, $writeSet, $except, $seconds, $microseconds);
        if (false === $selected) {
            return 'unavailable';
        }
        if (0 === $selected) {
            return 'timed_out';
        }

        return 'ok';
    }

    private static function pingResult(string $frame): string
    {
        if (self::isStatus($frame)) {
            return $frame;
        }

        return "PONG\n" === $frame ? 'pong' : 'unreadable';
    }

    private static function versionResult(string $frame): string
    {
        if (self::isStatus($frame)) {
            return $frame;
        }
        if (1 === preg_match('/^ClamAV [0-9]+(?:\.[0-9]+){1,3}\/(0|[1-9][0-9]*)\/[A-Za-z]{3} [A-Za-z]{3} [ 0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} [0-9]{4}\n$/', $frame, $matches)) {
            return $matches[1];
        }

        return 'unreadable';
    }

    private static function isStatus(string $frame): bool
    {
        return in_array($frame, ['eof', 'partial', 'timed_out', 'unavailable'], true);
    }
}

function clamav_access_probe_is_cli_entry(): bool
{
    if (\PHP_SAPI !== 'cli') {
        return false;
    }
    // The workflow decodes the helper and passes it on stdin: php -- "$socket".
    // That invocation has no real file path, so __FILE__ is the stdin label.
    if ('Standard input code' === __FILE__) {
        return true;
    }
    $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
    if (!is_string($script) || '' === $script) {
        return false;
    }
    $fileReal = realpath(__FILE__);
    $scriptReal = realpath($script);

    return is_string($fileReal) && is_string($scriptReal) && $fileReal === $scriptReal;
}

function clamav_access_probe_posix_credentials(): ?array
{
    if (!function_exists('posix_geteuid') || !function_exists('posix_getegid') || !function_exists('posix_getgroups')) {
        return null;
    }
    $euid = posix_geteuid();
    $egid = posix_getegid();
    $groups = posix_getgroups();
    if (!is_int($euid) || !is_int($egid) || !is_array($groups)) {
        return null;
    }

    return ['euid' => $euid, 'egid' => $egid, 'groups' => $groups];
}

/**
 * @param array<string, mixed>|null $credentials
 */
function clamav_access_probe_recorded_identity_matches(?array $credentials): bool
{
    if (null === $credentials) {
        return false;
    }
    $euid = $credentials['euid'] ?? null;
    $egid = $credentials['egid'] ?? null;
    $rawGroups = $credentials['groups'] ?? null;
    if (5002 !== $euid || 5002 !== $egid || !is_array($rawGroups)) {
        return false;
    }
    $groups = [];
    foreach ($rawGroups as $group) {
        if (!is_int($group) || $group < 0) {
            return false;
        }
        $groups[] = $group;
    }
    sort($groups);

    return [5002] === $groups;
}

/**
 * @param array<string, mixed>|null $credentials
 */
function clamav_access_probe_format_identity(?array $credentials): string
{
    $euid = is_array($credentials) ? ($credentials['euid'] ?? null) : null;
    $egid = is_array($credentials) ? ($credentials['egid'] ?? null) : null;
    $rawGroups = is_array($credentials) ? ($credentials['groups'] ?? null) : null;
    if (!is_int($euid) || !is_int($egid) || !is_array($rawGroups)) {
        return "process_identity=unavailable\n";
    }
    $lines = 'process_euid='.$euid."\n".'process_egid='.$egid."\n";
    foreach ($rawGroups as $group) {
        if (!is_int($group) || $group < 0) {
            return "process_identity=unavailable\n";
        }
        $lines .= 'process_group='.$group."\n";
    }
    if (!str_contains($lines, 'process_group=')) {
        return "process_identity=unavailable\n";
    }

    return $lines;
}

/**
 * @param array<string, mixed>|null $credentials
 */
function clamav_access_probe_emit_for_credentials(?array $credentials, callable $onMatch): void
{
    if (!clamav_access_probe_recorded_identity_matches($credentials)) {
        echo "process_identity_match=no\n";

        return;
    }
    $onMatch();
}

function clamav_access_probe_run(string $path): void
{
    $connect = static function (float $timeout) use ($path): mixed {
        if (!self_path_ok($path)) {
            return null;
        }
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('unix://'.$path, $errno, $errstr, min(ClamavAccessProbeClient::TIMEOUT_SECONDS, $timeout));

        return is_resource($socket) ? $socket : null;
    };
    echo ClamavAccessProbeClient::report($connect);
}

if (clamav_access_probe_is_cli_entry()) {
    $arguments = $_SERVER['argv'] ?? null;
    $path = is_array($arguments) && isset($arguments[1]) && is_string($arguments[1]) ? $arguments[1] : '';
    if ('--identity' === $path) {
        echo clamav_access_probe_format_identity(clamav_access_probe_posix_credentials());
        exit(0);
    }
    clamav_access_probe_emit_for_credentials(
        clamav_access_probe_posix_credentials(),
        static function () use ($path): void {
            clamav_access_probe_run($path);
        },
    );
}

function self_path_ok(string $path): bool
{
    return strlen($path) >= 2
        && strlen($path) <= 108
        && 1 === preg_match('#^/(?:[A-Za-z0-9._-]{1,64}/){0,16}[A-Za-z0-9._-]{1,64}$#', $path);
}

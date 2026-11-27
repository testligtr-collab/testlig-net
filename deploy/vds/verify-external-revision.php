<?php

declare(strict_types=1);

namespace Testlig\Deploy;

/*
 * Usage: php verify-external-revision.php <full-git-sha> <https-url> [<https-url> ...]
 *
 * Runs after a deploy, from the GitHub runner, over normal external HTTPS (certificate verification on, no
 * cookies, no cache-busting parameter). Each URL must serve the expected full SHA in X-Testlig-Release.
 * Exit codes: 0 verified, 1 deployed but the served release could not be verified, 2 invalid usage.
 * It opens no SSH connection and does not deploy, restart or roll back anything.
 */

require_once __DIR__.'/ExternalRevisionVerifier.php';

const ALLOWED_HOSTS = ['testlig.net', 'www.testlig.net'];
const MAX_ATTEMPTS = 10;
const INTERVAL_SECONDS = 6;
const BUDGET_SECONDS = 90;
const REQUEST_TIMEOUT_SECONDS = 10;

/**
 * Parses a `curl --dump-header -` dump of one request (no redirect following) into status and headers.
 *
 * @return array{status: int, headers: list<array{0: string, 1: string}>}|null
 */
function parseHeaderDump(string $dump): ?array
{
    $blocks = preg_split('/\r?\n\r?\n/', trim($dump, "\r\n")) ?: [];
    $block = end($blocks);
    if (false === $block || '' === $block) {
        return null;
    }
    $lines = preg_split('/\r?\n/', $block) ?: [];
    $statusLine = array_shift($lines);
    if (!\is_string($statusLine) || 1 !== preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})(?:\s|$)#', $statusLine, $match)) {
        return null;
    }
    $headers = [];
    foreach ($lines as $line) {
        $position = strpos($line, ':');
        if (false === $position || 0 === $position) {
            continue;
        }
        $headers[] = [substr($line, 0, $position), trim(substr($line, $position + 1))];
    }

    return ['status' => (int) $match[1], 'headers' => $headers];
}

/**
 * One HTTPS GET through the curl binary. No redirect following, no cookie jar, no curlrc, TLS verified.
 *
 * @return array{status: int, headers: list<array{0: string, 1: string}>, error: ?string}
 */
function curlTransport(string $url, int $timeoutSeconds): array
{
    $command = [
        'curl', '-q', '--silent', '--show-error',
        '--proto', '=https', '--tlsv1.2',
        '--connect-timeout', '5', '--max-time', (string) $timeoutSeconds,
        '--user-agent', 'testlig-deploy-revision-check/1',
        '--dump-header', '-', '--output', '/dev/null',
        $url,
    ];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!\is_resource($process)) {
        return ['status' => 0, 'headers' => [], 'error' => 'curl_not_started'];
    }
    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if (0 !== $exit) {
        return ['status' => 0, 'headers' => [], 'error' => 'curl_exit_'.$exit];
    }
    $parsed = parseHeaderDump(\is_string($stdout) ? $stdout : '');
    if (null === $parsed) {
        return ['status' => 0, 'headers' => [], 'error' => 'response_unreadable'];
    }

    return ['status' => $parsed['status'], 'headers' => $parsed['headers'], 'error' => null];
}

/**
 * @param list<string> $argv
 */
function runVerification(array $argv): int
{
    $expected = $argv[1] ?? '';
    $urls = \array_slice($argv, 2);
    if (1 !== preg_match('/^[0-9a-f]{40}$/', $expected) || [] === $urls) {
        fwrite(\STDERR, "revision_check_usage=invalid\n");

        return 2;
    }
    foreach ($urls as $url) {
        $parts = parse_url($url);
        if (!\is_array($parts) || 'https' !== strtolower($parts['scheme'] ?? '') || !\in_array(strtolower($parts['host'] ?? ''), ALLOWED_HOSTS, true)) {
            fwrite(\STDERR, "revision_check_usage=unexpected_host\n");

            return 2;
        }
    }

    $verifier = new ExternalRevisionVerifier(
        curlTransport(...),
        static function (int $seconds): void {
            sleep($seconds);
        },
        static fn (): float => microtime(true),
        static function (string $line): void {
            echo $line."\n";
        },
    );
    $failed = false;
    foreach ($urls as $url) {
        $result = $verifier->verify($url, $expected, ALLOWED_HOSTS, MAX_ATTEMPTS, INTERVAL_SECONDS, BUDGET_SECONDS, REQUEST_TIMEOUT_SECONDS);
        if (!$result['ok']) {
            $failed = true;
        }
    }
    if ($failed) {
        echo "revision_check_result=unverified\n";
        echo "Dağıtım etkinleştirildi, sunulan sürüm doğrulanamadı\n";

        return 1;
    }
    echo "revision_check_result=verified\n";
    echo "Beklenen sürüm dış HTTPS'te doğrulandı\n";

    return 0;
}

if (\PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && __FILE__ === realpath((string) $_SERVER['SCRIPT_FILENAME'])) {
    exit(runVerification($_SERVER['argv']));
}

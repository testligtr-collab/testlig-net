<?php

declare(strict_types=1);

namespace Testlig\Deploy;

/**
 * Checks, over normal external HTTPS, that the web application serves the release that was just deployed.
 *
 * A release being activated on disk and a page answering HTTP 200 do not prove that the visitor gets the new
 * code. This compares the full Git SHA the application puts in the X-Testlig-Release response header with the
 * SHA the deploy workflow packaged. Only the final response of a bounded, host-restricted redirect chain counts.
 * It is a read-only HTTPS GET check: it never opens SSH, deploys, restarts a service or rolls back.
 *
 * A transport performs one request without following redirects and returns:
 *   ['status' => int, 'headers' => list<array{0: string, 1: string}>, 'error' => ?string]
 */
final class ExternalRevisionVerifier
{
    public const HEADER = 'X-Testlig-Release';

    private const MAX_REDIRECTS = 3;

    /** @var callable(string, int): array{status: int, headers: list<array{0: string, 1: string}>, error: ?string} */
    private $transport;

    /** @var callable(int): void */
    private $sleep;

    /** @var callable(): float */
    private $clock;

    /** @var callable(string): void */
    private $log;

    /**
     * @param callable(string, int): array{status: int, headers: list<array{0: string, 1: string}>, error: ?string} $transport
     * @param callable(int): void                                                                                   $sleep
     * @param callable(): float                                                                                     $clock
     * @param callable(string): void                                                                                $log
     */
    public function __construct(callable $transport, callable $sleep, callable $clock, callable $log)
    {
        $this->transport = $transport;
        $this->sleep = $sleep;
        $this->clock = $clock;
        $this->log = $log;
    }

    /**
     * @param list<string> $allowedHosts lower-case host names the request and every redirect may use
     *
     * @return array{ok: bool, reason: string, attempts: int, observed: string, elapsed: int}
     */
    public function verify(
        string $url,
        string $expectedSha,
        array $allowedHosts,
        int $maxAttempts,
        int $intervalSeconds,
        int $budgetSeconds,
        int $requestTimeoutSeconds,
    ): array {
        $started = ($this->clock)();
        $attempt = 0;
        $last = ['reason' => 'not_attempted', 'observed' => 'none'];

        while ($attempt < $maxAttempts) {
            $elapsed = (int) floor(($this->clock)() - $started);
            if ($attempt > 0 && $elapsed >= $budgetSeconds) {
                break;
            }
            ++$attempt;
            $last = $this->attempt($url, $expectedSha, $allowedHosts, $requestTimeoutSeconds);
            $elapsed = (int) floor(($this->clock)() - $started);
            $this->line(\sprintf(
                'revision_check url=%s attempt=%d/%d http=%s observed=%s expected=%s result=%s elapsed=%ds',
                $last['url'],
                $attempt,
                $maxAttempts,
                $last['http'],
                $last['observed'],
                $expectedSha,
                $last['reason'],
                $elapsed,
            ));
            if ('verified' === $last['reason']) {
                return ['ok' => true, 'reason' => 'verified', 'attempts' => $attempt, 'observed' => $last['observed'], 'elapsed' => $elapsed];
            }
            if ($last['fatal']) {
                return ['ok' => false, 'reason' => $last['reason'], 'attempts' => $attempt, 'observed' => $last['observed'], 'elapsed' => $elapsed];
            }
            if ($attempt >= $maxAttempts || $elapsed + $intervalSeconds >= $budgetSeconds) {
                break;
            }
            ($this->sleep)($intervalSeconds);
        }

        $elapsed = (int) floor(($this->clock)() - $started);

        return ['ok' => false, 'reason' => $last['reason'], 'attempts' => $attempt, 'observed' => $last['observed'], 'elapsed' => $elapsed];
    }

    /**
     * @param list<string> $allowedHosts
     *
     * @return array{reason: string, observed: string, http: string, url: string, fatal: bool}
     */
    private function attempt(string $startUrl, string $expectedSha, array $allowedHosts, int $timeout): array
    {
        $current = $startUrl;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $parts = parse_url($current);
            $host = \is_array($parts) && isset($parts['host']) ? strtolower($parts['host']) : '';
            $scheme = \is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
            if ('https' !== $scheme || !\in_array($host, $allowedHosts, true)) {
                return $this->outcome('unexpected_host', 'none', 'none', $this->safeUrl($current), true);
            }

            $response = ($this->transport)($current, $timeout);
            if (null !== $response['error']) {
                return $this->outcome('transport_error', 'none', 'none', $this->safeUrl($current), false);
            }

            $status = $response['status'];
            if ($status >= 300 && $status < 400) {
                $next = $this->redirectTarget($current, $response['headers']);
                if (null === $next) {
                    return $this->outcome('redirect_invalid', 'none', (string) $status, $this->safeUrl($current), true);
                }
                $current = $next;

                continue;
            }

            if (200 !== $status) {
                return $this->outcome('http_error', 'none', (string) $status, $this->safeUrl($current), false);
            }

            return $this->evaluate($response['headers'], $expectedSha, $this->safeUrl($current));
        }

        return $this->outcome('too_many_redirects', 'none', 'none', $this->safeUrl($current), true);
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     *
     * @return array{reason: string, observed: string, http: string, url: string, fatal: bool}
     */
    private function evaluate(array $headers, string $expectedSha, string $url): array
    {
        $values = [];
        foreach ($headers as [$name, $value]) {
            if (0 === strcasecmp($name, self::HEADER)) {
                $values[] = trim($value);
            }
        }
        if ([] === $values) {
            return $this->outcome('header_missing', 'none', '200', $url, false);
        }
        if (\count($values) > 1) {
            return $this->outcome('header_duplicate', 'duplicate', '200', $url, false);
        }
        if (1 !== preg_match('/^[0-9a-f]{40}$/', $values[0])) {
            return $this->outcome('header_invalid', 'invalid', '200', $url, false);
        }
        if ($values[0] !== $expectedSha) {
            return $this->outcome('sha_mismatch', $values[0], '200', $url, false);
        }

        return $this->outcome('verified', $values[0], '200', $url, false);
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     */
    private function redirectTarget(string $current, array $headers): ?string
    {
        $locations = [];
        foreach ($headers as [$name, $value]) {
            if (0 === strcasecmp($name, 'Location')) {
                $locations[] = trim($value);
            }
        }
        if (1 !== \count($locations) || '' === $locations[0] || 1 === preg_match('/[\x00-\x20\x7f]/', $locations[0])) {
            return null;
        }
        $location = $locations[0];
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $parts = parse_url($current);
            if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                return null;
            }

            return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$location;
        }
        if (str_starts_with(strtolower($location), 'https://')) {
            return $location;
        }

        return null;
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['host'])) {
            return 'invalid';
        }
        $host = preg_replace('/[^a-z0-9.-]/', '', strtolower($parts['host'])) ?? '';
        $path = $parts['path'] ?? '/';
        $path = preg_replace('/[^A-Za-z0-9\/._~-]/', '', $path) ?? '/';

        return $host.('' === $path ? '/' : $path);
    }

    /**
     * @return array{reason: string, observed: string, http: string, url: string, fatal: bool}
     */
    private function outcome(string $reason, string $observed, string $http, string $url, bool $fatal): array
    {
        return ['reason' => $reason, 'observed' => $observed, 'http' => $http, 'url' => $url, 'fatal' => $fatal];
    }

    private function line(string $message): void
    {
        ($this->log)($message);
    }
}

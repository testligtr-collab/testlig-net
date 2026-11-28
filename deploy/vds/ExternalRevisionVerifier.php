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
 * All routes share ONE time budget and are judged in rounds. A round asks every route once; the check
 * succeeds only in a round in which every route serves the expected SHA. A match a route showed in an
 * earlier round is never combined with another route's match in a later round.
 *
 * A transport performs one request without following redirects and returns:
 *   ['status' => int, 'headers' => list<array{0: string, 1: string}>, 'error' => ?string]
 *
 * The clock must be monotonic and return seconds. The budget bounds the sleeps AND every request: a request
 * is never given a timeout longer than the time that is left.
 */
final class ExternalRevisionVerifier
{
    public const HEADER = 'X-Testlig-Release';

    /** Shortest remaining time, in seconds, for which another round is still worth starting. */
    public const MIN_ROUND_SECONDS = 5;

    /** Seconds between two progress lines while at least one route is still not verified. */
    public const PROGRESS_SECONDS = 300;

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
     * @param callable(): float                                                                                     $clock     monotonic seconds
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
     * @param list<string> $urls         start URLs; each is requested once per round
     * @param list<string> $allowedHosts lower-case host names the request and every redirect may use
     * @param list<int>    $delays       seconds to wait after round 1, 2, ...; the last value repeats
     *
     * @return array{ok: bool, reason: string, rounds: int, elapsed: float, routes: list<array{url: string, reason: string, observed: string, http: string, lastValid: string}>}
     */
    public function verifyRoutes(
        array $urls,
        string $expectedSha,
        array $allowedHosts,
        array $delays,
        int $budgetSeconds,
        int $requestTimeoutSeconds,
    ): array {
        $started = ($this->clock)();
        $deadline = $started + $budgetSeconds;

        $routes = [];
        foreach ($urls as $url) {
            $routes[] = ['url' => $this->safeUrl($url), 'reason' => 'not_attempted', 'observed' => 'none', 'http' => 'none', 'lastValid' => 'none'];
        }

        if (1 !== preg_match('/^[0-9a-f]{40}$/', $expectedSha) || [] === $urls || $budgetSeconds < 1 || $requestTimeoutSeconds < 1) {
            return $this->finish($routes, 'expected_invalid', 0, $started, $expectedSha, $budgetSeconds);
        }
        foreach ($urls as $url) {
            if (!$this->isAllowed($url, $allowedHosts)) {
                return $this->finish($routes, 'unexpected_host', 0, $started, $expectedSha, $budgetSeconds);
            }
        }

        $delays = [] === $delays ? [(int) self::MIN_ROUND_SECONDS] : array_values($delays);
        $signatures = [];
        $round = 0;
        $nextProgress = self::PROGRESS_SECONDS;

        while (true) {
            ++$round;
            $allVerified = true;
            foreach ($urls as $index => $url) {
                $outcome = $this->attempt($url, $expectedSha, $allowedHosts, $requestTimeoutSeconds, $deadline);
                $routes[$index]['reason'] = $outcome['reason'];
                $routes[$index]['observed'] = $outcome['observed'];
                $routes[$index]['http'] = $outcome['http'];
                if (1 === preg_match('/^[0-9a-f]{40}$/', $outcome['observed'])) {
                    $routes[$index]['lastValid'] = $outcome['observed'];
                }

                // Log a route's result only when it changes, so a long wait does not repeat the same line.
                $signature = $outcome['reason'].'|'.$outcome['http'].'|'.$outcome['observed'];
                if (($signatures[$index] ?? null) !== $signature) {
                    $signatures[$index] = $signature;
                    $this->line(\sprintf(
                        'revision_check round=%d url=%s http=%s observed=%s expected=%s result=%s elapsed=%ds',
                        $round,
                        $outcome['url'],
                        $outcome['http'],
                        $outcome['observed'],
                        $expectedSha,
                        $outcome['reason'],
                        $this->elapsed($started),
                    ));
                }

                if ($outcome['fatal']) {
                    return $this->finish($routes, $outcome['reason'], $round, $started, $expectedSha, $budgetSeconds);
                }
                if ('verified' !== $outcome['reason']) {
                    $allVerified = false;
                }
            }

            if ($allVerified) {
                return $this->finish($routes, 'verified', $round, $started, $expectedSha, $budgetSeconds);
            }

            $remaining = $deadline - ($this->clock)();
            $usable = $remaining - self::MIN_ROUND_SECONDS;
            if ($usable < 1) {
                break;
            }
            // The wait never exceeds what is left of the shared budget minus one useful round.
            $delay = min(max(1, $delays[min($round - 1, \count($delays) - 1)]), (int) floor($usable));
            ($this->sleep)($delay);

            $elapsed = $this->elapsed($started);
            if ($elapsed >= $nextProgress) {
                $nextProgress = (intdiv($elapsed, self::PROGRESS_SECONDS) + 1) * self::PROGRESS_SECONDS;
                $this->line(\sprintf('revision_check waiting round=%d elapsed=%ds budget=%ds expected=%s', $round, $elapsed, $budgetSeconds, $expectedSha));
            }
            if ($deadline - ($this->clock)() < 1) {
                break;
            }
        }

        return $this->finish($routes, $this->worstReason($routes), $round, $started, $expectedSha, $budgetSeconds);
    }

    /**
     * @param list<array{url: string, reason: string, observed: string, http: string, lastValid: string}> $routes
     *
     * @return array{ok: bool, reason: string, rounds: int, elapsed: float, routes: list<array{url: string, reason: string, observed: string, http: string, lastValid: string}>}
     */
    private function finish(array $routes, string $reason, int $rounds, float $started, string $expectedSha, int $budgetSeconds): array
    {
        $elapsed = max(0.0, ($this->clock)() - $started);
        if (0 === $rounds) {
            // Rejected before any request: every route reports the reason, none was attempted.
            foreach ($routes as $index => $route) {
                $routes[$index]['reason'] = $reason;
            }
        }
        foreach ($routes as $route) {
            $this->line(\sprintf(
                'revision_check_summary url=%s rounds=%d http=%s last_valid_sha=%s result=%s elapsed=%ds budget=%ds expected=%s',
                $route['url'],
                $rounds,
                $route['http'],
                $route['lastValid'],
                $route['reason'],
                (int) floor($elapsed),
                $budgetSeconds,
                $expectedSha,
            ));
        }

        return ['ok' => 'verified' === $reason, 'reason' => $reason, 'rounds' => $rounds, 'elapsed' => $elapsed, 'routes' => $routes];
    }

    /**
     * @param list<array{url: string, reason: string, observed: string, http: string, lastValid: string}> $routes
     */
    private function worstReason(array $routes): string
    {
        foreach ($routes as $route) {
            if ('verified' !== $route['reason']) {
                return $route['reason'];
            }
        }

        return 'verified';
    }

    private function elapsed(float $started): int
    {
        return (int) floor(max(0.0, ($this->clock)() - $started));
    }

    /**
     * @param list<string> $allowedHosts
     */
    private function isAllowed(string $url, array $allowedHosts): bool
    {
        $parts = parse_url($url);
        $host = \is_array($parts) && isset($parts['host']) ? strtolower($parts['host']) : '';
        $scheme = \is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';

        return 'https' === $scheme && \in_array($host, $allowedHosts, true);
    }

    /**
     * @param list<string> $allowedHosts
     *
     * @return array{reason: string, observed: string, http: string, url: string, fatal: bool}
     */
    private function attempt(string $startUrl, string $expectedSha, array $allowedHosts, int $requestTimeout, float $deadline): array
    {
        $current = $startUrl;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            if (!$this->isAllowed($current, $allowedHosts)) {
                return $this->outcome('unexpected_host', 'none', 'none', $this->safeUrl($current), true);
            }

            // The shared budget bounds every request, including each redirect hop.
            $timeout = min($requestTimeout, (int) floor($deadline - ($this->clock)()));
            if ($timeout < 1) {
                return $this->outcome('budget_exhausted', 'none', 'none', $this->safeUrl($current), false);
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

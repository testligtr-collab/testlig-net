<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Testlig\Deploy\ExternalRevisionVerifier;

require_once \dirname(__DIR__, 2).'/deploy/vds/ExternalRevisionVerifier.php';

/**
 * The verifier judges all routes in rounds against ONE shared budget. These tests use a fake monotonic clock,
 * a fake sleep and canned HTTP responses: they never wait and never touch the network.
 */
final class ExternalRevisionVerifierTest extends TestCase
{
    private const NEW = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    private const OLD = '0000111122223333444455556666777788889999';
    private const HOSTS = ['testlig.net', 'www.testlig.net'];
    private const HOME = 'https://testlig.net/';
    private const CATALOG = 'https://testlig.net/dersler';
    private const BUDGET = 1200;
    private const SCHEDULE = [15, 30, 45, 60];
    private const START = 500.0;

    private float $now = self::START;

    /** @var list<string> */
    private array $logs = [];

    /** @var list<int> */
    private array $sleeps = [];

    /** @var list<array{url: string, timeout: int, at: float}> */
    private array $requests = [];

    /** @var array<string, int> */
    private array $calls = [];

    public function testBothRoutesCorrectInTheFirstRoundVerifyWithoutWaiting(): void
    {
        $result = $this->verify(fn (): array => $this->ok(self::NEW));

        self::assertTrue($result['ok']);
        self::assertSame('verified', $result['reason']);
        self::assertSame(1, $result['rounds']);
        self::assertSame([], $this->sleeps);
        self::assertSame([self::HOME, self::CATALOG], $this->urls());
        self::assertLessThan(5.0, $result['elapsed']);
    }

    public function testHeaderNameIsCaseInsensitive(): void
    {
        $result = $this->verify(fn (): array => $this->response(200, [['x-testlig-RELEASE', ' '.self::NEW.' ']]));

        self::assertTrue($result['ok']);
    }

    public function testBothRoutesOldThenCorrectAfterThirteenMinutesPassWithinTheSharedBudget(): void
    {
        $switchAt = self::START + 13 * 60;
        $result = $this->verify(fn (): array => $this->ok($this->now >= $switchAt ? self::NEW : self::OLD));

        self::assertTrue($result['ok']);
        self::assertGreaterThanOrEqual(13 * 60, $result['elapsed']);
        self::assertLessThanOrEqual(self::BUDGET, $result['elapsed']);
        self::assertGreaterThan(5, $result['rounds']);
        // Short at first, then at most 60 seconds apart.
        self::assertSame([15, 30, 45, 60], \array_slice($this->sleeps, 0, 4));
        self::assertLessThanOrEqual(60, max(0, ...$this->sleeps));
        $this->assertWithinBudget();
    }

    public function testOneRouteCorrectAndTheOtherOldKeepsWaitingUntilBothMatch(): void
    {
        $catalogSwitch = self::START + 300;
        $result = $this->verify(function (string $url) use ($catalogSwitch): array {
            if (self::HOME === $url) {
                return $this->ok(self::NEW);
            }

            return $this->ok($this->now >= $catalogSwitch ? self::NEW : self::OLD);
        });

        self::assertTrue($result['ok']);
        self::assertGreaterThan(1, $result['rounds']);
        self::assertGreaterThanOrEqual(300, $result['elapsed']);
        // The home route matched from the first round on; that earlier match alone never ended the check.
        self::assertGreaterThan(2, \count($this->requests));
    }

    public function testMatchesThatMoveBetweenRoundsNeverCountAndSimultaneousMatchSucceeds(): void
    {
        $result = $this->verify(function (string $url): array {
            $round = $this->calls[$url] ?? 0;
            // Round 1: home new, catalog old. Round 2: home old, catalog new. Round 3: both new.
            if ($round >= 3) {
                return $this->ok(self::NEW);
            }
            $homeNew = 1 === $round;

            return $this->ok((self::HOME === $url) === $homeNew ? self::NEW : self::OLD);
        });

        self::assertTrue($result['ok']);
        self::assertSame(3, $result['rounds']);
        self::assertSame([15, 30], $this->sleeps);
    }

    public function testMatchesThatNeverCoincideEndWithUnverified(): void
    {
        $result = $this->verify(function (string $url): array {
            $round = $this->calls[$url] ?? 0;

            // The routes alternate: exactly one of them shows the new SHA in every round.
            return $this->ok((self::HOME === $url) === (1 === $round % 2) ? self::NEW : self::OLD);
        });

        self::assertFalse($result['ok']);
        self::assertSame('sha_mismatch', $result['reason']);
        self::assertGreaterThan(10, $result['rounds']);
        $this->assertWithinBudget();
    }

    public function testRoutesThatStayOldEndWithUnverifiedAfterOneSharedBudget(): void
    {
        $result = $this->verify(fn (): array => $this->ok(self::OLD));

        self::assertFalse($result['ok']);
        self::assertSame('sha_mismatch', $result['reason']);
        self::assertLessThanOrEqual(self::BUDGET, $result['elapsed']);
        // One budget for both routes, not 20 minutes each.
        self::assertGreaterThan(self::BUDGET - 120, $result['elapsed']);
        $this->assertWithinBudget();
        foreach ($result['routes'] as $route) {
            self::assertSame('sha_mismatch', $route['reason']);
            self::assertSame(self::OLD, $route['lastValid']);
            self::assertSame('200', $route['http']);
        }
        self::assertSame(intdiv(\count($this->requests), 2), $result['rounds']);
    }

    public function testHangingRequestsAndRedirectsDoNotOpenASecondBudget(): void
    {
        // Every request needs far longer than its timeout, so each one ends exactly at its timeout.
        $result = $this->verify(fn (): array => $this->ok(self::NEW), latency: 100.0);

        self::assertFalse($result['ok']);
        self::assertSame('transport_error', $result['routes'][0]['reason']);
        self::assertLessThanOrEqual(self::BUDGET, $result['elapsed']);
        foreach ($this->requests as $request) {
            self::assertLessThanOrEqual(10, $request['timeout']);
            self::assertGreaterThanOrEqual(1, $request['timeout']);
        }
        $this->assertWithinBudget();
    }

    public function testRedirectHopsShareTheSameBudgetAndStopWhenItIsUsedUp(): void
    {
        // Budget 2 s, every request takes 1.5 s: the redirect hop would start with 0.5 s left and is not sent.
        $result = $this->verify(
            function (string $url): array {
                return 'https://testlig.net/' === $url
                    ? $this->response(301, [['Location', 'https://www.testlig.net/'], ['X-Testlig-Release', self::NEW]])
                    : $this->ok(self::NEW);
            },
            budget: 2,
            latency: 1.5,
            urls: [self::HOME],
        );

        self::assertFalse($result['ok']);
        self::assertSame('budget_exhausted', $result['routes'][0]['reason']);
        self::assertSame([self::HOME], $this->urls());
        $this->assertWithinBudget(2);
    }

    public function testLastRoundNeverSleepsOrRequestsBeyondTheRemainingTime(): void
    {
        // A short budget makes the end-of-budget arithmetic visible: waits and timeouts shrink to what is left.
        $result = $this->verify(fn (): array => $this->ok(self::OLD), budget: 40, delays: [15], latency: 2.0);

        self::assertFalse($result['ok']);
        self::assertLessThanOrEqual(40.0, $result['elapsed']);
        $this->assertWithinBudget(40);
        $clock = self::START;
        foreach ($this->requests as $request) {
            self::assertLessThanOrEqual(self::START + 40, $request['at'] + $request['timeout']);
            $clock = $request['at'];
        }
        self::assertGreaterThan(self::START, $clock, 'more than one round ran');
        foreach ($this->sleeps as $sleep) {
            self::assertLessThanOrEqual(15, $sleep);
        }
    }

    /**
     * @param array{status: int, headers: list<array{0: string, 1: string}>, error: ?string} $response
     */
    #[DataProvider('brokenResponses')]
    public function testBrokenResponsesNeverVerifyEvenWhenTheOtherRouteMatches(array $response, string $reason): void
    {
        $result = $this->verify(
            fn (string $url): array => self::HOME === $url ? $this->ok(self::NEW) : $response,
            budget: 30,
            delays: [5],
        );

        self::assertFalse($result['ok']);
        self::assertSame($reason, $result['reason']);
        self::assertSame('verified', $result['routes'][0]['reason']);
        self::assertSame($reason, $result['routes'][1]['reason']);
        self::assertSame('none', $result['routes'][1]['lastValid']);
    }

    /**
     * @return iterable<string, array{array{status: int, headers: list<array{0: string, 1: string}>, error: ?string}, string}>
     */
    public static function brokenResponses(): iterable
    {
        yield 'missing header' => [['status' => 200, 'headers' => [['Content-Type', 'text/html']], 'error' => null], 'header_missing'];
        yield 'duplicate header with the same value' => [['status' => 200, 'headers' => [['X-Testlig-Release', self::NEW], ['x-testlig-release', self::NEW]], 'error' => null], 'header_duplicate'];
        yield 'duplicate header with a stale value' => [['status' => 200, 'headers' => [['X-Testlig-Release', self::OLD], ['X-Testlig-Release', self::NEW]], 'error' => null], 'header_duplicate'];
        yield 'uppercase hex' => [['status' => 200, 'headers' => [['X-Testlig-Release', strtoupper(self::NEW)]], 'error' => null], 'header_invalid'];
        yield 'short sha' => [['status' => 200, 'headers' => [['X-Testlig-Release', substr(self::NEW, 0, 12)]], 'error' => null], 'header_invalid'];
        yield 'sha with suffix' => [['status' => 200, 'headers' => [['X-Testlig-Release', self::NEW.'-dirty']], 'error' => null], 'header_invalid'];
        yield 'empty value' => [['status' => 200, 'headers' => [['X-Testlig-Release', '']], 'error' => null], 'header_invalid'];
        yield 'server error' => [['status' => 500, 'headers' => [['X-Testlig-Release', self::NEW]], 'error' => null], 'http_error'];
        yield 'not found' => [['status' => 404, 'headers' => [['X-Testlig-Release', self::NEW]], 'error' => null], 'http_error'];
        yield '204 without a body' => [['status' => 204, 'headers' => [['X-Testlig-Release', self::NEW]], 'error' => null], 'http_error'];
        yield 'timeout' => [['status' => 0, 'headers' => [], 'error' => 'curl_exit_28'], 'transport_error'];
    }

    public function testTransientProblemOnOneRouteRecoversWithinTheBudget(): void
    {
        $result = $this->verify(function (string $url): array {
            $round = $this->calls[$url] ?? 0;
            if (self::CATALOG === $url && $round <= 2) {
                return ['status' => 503, 'headers' => [], 'error' => null];
            }

            return $this->ok(self::NEW);
        });

        self::assertTrue($result['ok']);
        self::assertSame(3, $result['rounds']);
    }

    public function testOnlyTheFinalResponseAfterARedirectCounts(): void
    {
        $result = $this->verify(
            fn (string $url): array => self::HOME === $url
                ? $this->redirect('https://www.testlig.net/', self::NEW)
                : $this->ok(self::OLD),
            budget: 20,
            urls: [self::HOME],
        );

        self::assertFalse($result['ok']);
        self::assertSame('sha_mismatch', $result['reason']);
        self::assertSame(self::OLD, $result['routes'][0]['lastValid']);
        self::assertSame(['https://testlig.net/', 'https://www.testlig.net/'], \array_slice($this->urls(), 0, 2));
    }

    public function testRedirectHeaderIsIgnoredWhenTheFinalResponseMatches(): void
    {
        $result = $this->verify(
            fn (string $url): array => self::HOME === $url ? $this->redirect('/dersler', self::OLD) : $this->ok(self::NEW),
            urls: [self::HOME],
        );

        self::assertTrue($result['ok']);
        self::assertSame(['https://testlig.net/', 'https://testlig.net/dersler'], $this->urls());
    }

    #[DataProvider('forbiddenRedirects')]
    public function testRedirectToAnUnexpectedTargetStopsEarlyWithoutFetchingIt(string $location, string $reason): void
    {
        $result = $this->verify(fn (string $url): array => self::HOME === $url ? $this->redirect($location, self::NEW) : $this->ok(self::NEW));

        self::assertFalse($result['ok']);
        self::assertSame($reason, $result['reason']);
        self::assertSame(1, $result['rounds']);
        self::assertSame([self::HOME], $this->urls());
        self::assertSame([], $this->sleeps);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenRedirects(): iterable
    {
        yield 'other host' => ['https://evil.example/', 'unexpected_host'];
        yield 'lookalike host' => ['https://testlig.net.evil.example/', 'unexpected_host'];
        yield 'plain http' => ['http://testlig.net/', 'redirect_invalid'];
        yield 'protocol relative' => ['//evil.example/', 'redirect_invalid'];
        yield 'relative without slash' => ['dersler', 'redirect_invalid'];
    }

    public function testRedirectLoopIsStoppedEarly(): void
    {
        $result = $this->verify(fn (): array => $this->redirect('/', self::NEW), urls: [self::HOME]);

        self::assertFalse($result['ok']);
        self::assertSame('too_many_redirects', $result['reason']);
        self::assertSame(1, $result['rounds']);
        self::assertCount(4, $this->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testStartUrlOutsideTheAllowedHostsSendsNoRequestAtAll(): void
    {
        $result = $this->verify(fn (): array => $this->ok(self::NEW), urls: [self::HOME, 'https://evil.example/']);

        self::assertFalse($result['ok']);
        self::assertSame('unexpected_host', $result['reason']);
        self::assertSame(0, $result['rounds']);
        self::assertSame([], $this->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testInvalidExpectedShaFailsAtOnceWithoutRequestsOrWaiting(): void
    {
        $result = $this->verify(fn (): array => $this->ok(self::NEW), expected: strtoupper(self::NEW));

        self::assertFalse($result['ok']);
        self::assertSame('expected_invalid', $result['reason']);
        self::assertSame([], $this->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testResultIsLoggedOnlyWhenItChangesAndEveryRouteGetsASummary(): void
    {
        $this->verify(fn (): array => $this->ok(self::OLD));

        $changes = array_values(array_filter($this->logs, static fn (string $line): bool => str_starts_with($line, 'revision_check round=')));
        // Many rounds with the same result: one line per route, not one per round.
        self::assertCount(2, $changes);
        self::assertStringContainsString('url=testlig.net/ ', $changes[0]);
        self::assertStringContainsString('url=testlig.net/dersler ', $changes[1]);

        $summaries = array_values(array_filter($this->logs, static fn (string $line): bool => str_starts_with($line, 'revision_check_summary ')));
        self::assertCount(2, $summaries);
        foreach ($summaries as $summary) {
            self::assertStringContainsString('last_valid_sha='.self::OLD, $summary);
            self::assertStringContainsString('result=sha_mismatch', $summary);
            self::assertStringContainsString('http=200', $summary);
            self::assertStringContainsString('expected='.self::NEW, $summary);
            self::assertStringContainsString('budget=1200s', $summary);
            self::assertMatchesRegularExpression('/rounds=\d+ /', $summary);
            self::assertMatchesRegularExpression('/elapsed=\d+s/', $summary);
        }
        $progress = array_filter($this->logs, static fn (string $line): bool => str_starts_with($line, 'revision_check waiting '));
        self::assertGreaterThanOrEqual(2, \count($progress));
        self::assertLessThanOrEqual(4, \count($progress));
    }

    public function testLogShowsExpectedAndObservedShaButNoBodyOrCookies(): void
    {
        $this->verify(
            fn (): array => $this->response(200, [['Set-Cookie', 'session=top-secret-cookie'], ['X-Testlig-Release', self::OLD]]),
            budget: 6,
            urls: [self::HOME],
        );

        $log = implode("\n", $this->logs);
        self::assertStringContainsString('round=1', $log);
        self::assertStringContainsString('http=200', $log);
        self::assertStringContainsString('observed='.self::OLD, $log);
        self::assertStringContainsString('expected='.self::NEW, $log);
        self::assertStringContainsString('url=testlig.net/', $log);
        self::assertStringNotContainsString('top-secret-cookie', $log);
        self::assertStringNotContainsString('Set-Cookie', $log);
    }

    public function testCheckNeverOpensSshOrTouchesTheDeployment(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/deploy/vds/ExternalRevisionVerifier.php');
        $cli = (string) file_get_contents(\dirname(__DIR__, 2).'/deploy/vds/verify-external-revision.php');

        foreach ([$source, $cli] as $text) {
            $code = preg_replace('#/\*.*?\*/#s', '', $text) ?? '';
            self::assertDoesNotMatchRegularExpression('/\b(ssh|scp|rsync)\b/i', $code);
            self::assertStringNotContainsString('rollback', $code);
            self::assertStringNotContainsString('lswsctrl', $text);
            self::assertStringNotContainsString('systemctl', $text);
        }
        // The verifier itself starts no process at all; the CLI starts only the curl binary.
        foreach (['proc_open', 'exec(', 'shell_exec', 'system(', 'passthru', 'popen'] as $call) {
            self::assertStringNotContainsString($call, $source, $call);
        }
        self::assertSame(1, substr_count($cli, 'proc_open('));
        self::assertStringContainsString("'curl', '-q'", $cli);
    }

    /**
     * @param callable(string): array{status: int, headers: list<array{0: string, 1: string}>, error: ?string} $responder
     * @param list<string>|null                                                                                $urls
     * @param list<int>|null                                                                                   $delays
     *
     * @return array{ok: bool, reason: string, rounds: int, elapsed: float, routes: list<array{url: string, reason: string, observed: string, http: string, lastValid: string}>}
     */
    private function verify(callable $responder, int $budget = self::BUDGET, float $latency = 1.0, ?array $urls = null, ?array $delays = null, string $expected = self::NEW): array
    {
        $this->now = self::START;
        $this->logs = [];
        $this->sleeps = [];
        $this->requests = [];
        $this->calls = [];

        $verifier = new ExternalRevisionVerifier(
            function (string $url, int $timeout) use ($responder, $latency): array {
                $this->requests[] = ['url' => $url, 'timeout' => $timeout, 'at' => $this->now];
                self::assertGreaterThan(0, $timeout);
                self::assertLessThanOrEqual(10, $timeout);
                // A round counts per start route: the responder sees how many times that route was asked.
                $this->calls[$url] = ($this->calls[$url] ?? 0) + 1;
                if ($latency > $timeout) {
                    $this->now += $timeout;

                    return ['status' => 0, 'headers' => [], 'error' => 'curl_exit_28'];
                }
                $this->now += $latency;

                return $responder($url);
            },
            function (int $seconds): void {
                $this->sleeps[] = $seconds;
                $this->now += $seconds;
            },
            fn (): float => $this->now,
            function (string $line): void {
                $this->logs[] = $line;
            },
        );

        return $verifier->verifyRoutes($urls ?? [self::HOME, self::CATALOG], $expected, self::HOSTS, $delays ?? self::SCHEDULE, $budget, 10);
    }

    /**
     * Every request must start and, with its timeout, end inside the one shared budget.
     */
    private function assertWithinBudget(int $budget = self::BUDGET): void
    {
        $deadline = self::START + $budget;
        foreach ($this->requests as $request) {
            self::assertLessThanOrEqual($deadline, $request['at'] + $request['timeout'], 'request ends after the shared budget');
        }
        self::assertLessThanOrEqual($deadline, $this->now, 'the clock never passes the shared budget');
    }

    /**
     * @return list<string>
     */
    private function urls(): array
    {
        return array_map(static fn (array $request): string => $request['url'], $this->requests);
    }

    /**
     * @return array{status: int, headers: list<array{0: string, 1: string}>, error: ?string}
     */
    private function ok(string $sha): array
    {
        return $this->response(200, [['Content-Type', 'text/html'], ['X-Testlig-Release', $sha]]);
    }

    /**
     * @return array{status: int, headers: list<array{0: string, 1: string}>, error: ?string}
     */
    private function redirect(string $location, string $sha): array
    {
        return $this->response(301, [['Location', $location], ['X-Testlig-Release', $sha]]);
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     *
     * @return array{status: int, headers: list<array{0: string, 1: string}>, error: ?string}
     */
    private function response(int $status, array $headers): array
    {
        return ['status' => $status, 'headers' => $headers, 'error' => null];
    }
}

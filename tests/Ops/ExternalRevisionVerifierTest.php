<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Testlig\Deploy\ExternalRevisionVerifier;

require_once \dirname(__DIR__, 2).'/deploy/vds/ExternalRevisionVerifier.php';

final class ExternalRevisionVerifierTest extends TestCase
{
    private const NEW = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    private const OLD = '0000111122223333444455556666777788889999';
    private const HOSTS = ['testlig.net', 'www.testlig.net'];

    private float $now = 0.0;

    /** @var list<string> */
    private array $logs = [];

    /** @var list<int> */
    private array $sleeps = [];

    /** @var list<string> */
    private array $requested = [];

    public function testServedExpectedShaVerifiesOnTheFirstAttempt(): void
    {
        $result = $this->verify([$this->ok(self::NEW)]);

        self::assertTrue($result['ok']);
        self::assertSame('verified', $result['reason']);
        self::assertSame(1, $result['attempts']);
        self::assertSame([], $this->sleeps);
        self::assertSame(['https://testlig.net/'], $this->requested);
    }

    public function testHeaderNameIsCaseInsensitive(): void
    {
        $result = $this->verify([$this->response(200, [['x-testlig-RELEASE', ' '.self::NEW.' ']])]);

        self::assertTrue($result['ok']);
    }

    public function testOldShaUntilTheTimeLimitFails(): void
    {
        $result = $this->verify([$this->ok(self::OLD)], maxAttempts: 4, interval: 2, budget: 100);

        self::assertFalse($result['ok']);
        self::assertSame('sha_mismatch', $result['reason']);
        self::assertSame(self::OLD, $result['observed']);
        self::assertSame(4, $result['attempts']);
        self::assertSame([2, 2, 2], $this->sleeps);
    }

    public function testBudgetStopsRetriesBeforeTheAttemptLimit(): void
    {
        $result = $this->verify([$this->ok(self::OLD)], maxAttempts: 100, interval: 10, budget: 25);

        self::assertFalse($result['ok']);
        self::assertSame(3, $result['attempts']);
        self::assertSame([10, 10], $this->sleeps);
    }

    public function testOldShaFirstThenExpectedShaPassesWithinTheLimit(): void
    {
        $result = $this->verify([$this->ok(self::OLD), $this->ok(self::OLD), $this->ok(self::NEW)], maxAttempts: 5, interval: 3, budget: 60);

        self::assertTrue($result['ok']);
        self::assertSame(3, $result['attempts']);
        self::assertSame([3, 3], $this->sleeps);
    }

    /**
     * @param array{status: int, headers: list<array{0: string, 1: string}>, error: ?string} $response
     */
    #[DataProvider('brokenResponses')]
    public function testBrokenResponsesFailWithAClearReason(array $response, string $reason): void
    {
        $result = $this->verify([$response], maxAttempts: 2, interval: 1, budget: 30);

        self::assertFalse($result['ok']);
        self::assertSame($reason, $result['reason']);
        self::assertSame(2, $result['attempts']);
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
        yield 'timeout' => [['status' => 0, 'headers' => [], 'error' => 'curl_exit_28'], 'transport_error'];
    }

    public function testOnlyTheFinalResponseAfterARedirectCounts(): void
    {
        $result = $this->verify([
            $this->redirect('https://www.testlig.net/', self::NEW),
            $this->ok(self::OLD),
        ], maxAttempts: 1);

        self::assertFalse($result['ok']);
        self::assertSame('sha_mismatch', $result['reason']);
        self::assertSame(self::OLD, $result['observed']);
        self::assertSame(['https://testlig.net/', 'https://www.testlig.net/'], $this->requested);
    }

    public function testRedirectHeaderIsIgnoredWhenTheFinalResponseMatches(): void
    {
        $result = $this->verify([
            $this->redirect('/dersler', self::OLD),
            $this->ok(self::NEW),
        ]);

        self::assertTrue($result['ok']);
        self::assertSame(['https://testlig.net/', 'https://testlig.net/dersler'], $this->requested);
    }

    #[DataProvider('forbiddenRedirects')]
    public function testRedirectToAnUnexpectedTargetStopsWithoutFetchingIt(string $location, string $reason): void
    {
        $result = $this->verify([$this->redirect($location, self::NEW), $this->ok(self::NEW)], maxAttempts: 5);

        self::assertFalse($result['ok']);
        self::assertSame($reason, $result['reason']);
        self::assertSame(1, $result['attempts']);
        self::assertSame(['https://testlig.net/'], $this->requested);
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

    public function testRedirectLoopIsStopped(): void
    {
        $result = $this->verify([$this->redirect('/', self::NEW)], maxAttempts: 5);

        self::assertFalse($result['ok']);
        self::assertSame('too_many_redirects', $result['reason']);
        self::assertSame(1, $result['attempts']);
        self::assertCount(4, $this->requested);
    }

    public function testStartUrlOutsideTheAllowedHostsIsNotRequested(): void
    {
        $result = $this->verify([$this->ok(self::NEW)], url: 'https://evil.example/');

        self::assertFalse($result['ok']);
        self::assertSame('unexpected_host', $result['reason']);
        self::assertSame([], $this->requested);
    }

    public function testLogShowsExpectedAndObservedShaButNoBodyOrCookies(): void
    {
        $this->verify([
            $this->response(200, [['Set-Cookie', 'session=top-secret-cookie'], ['X-Testlig-Release', self::OLD]]),
        ], maxAttempts: 1);

        $log = implode("\n", $this->logs);
        self::assertStringContainsString('attempt=1/1', $log);
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
            self::assertDoesNotMatchRegularExpression('/\b(ssh|scp|rsync)\b/i', preg_replace('#/\*.*?\*/#s', '', $text) ?? '');
            self::assertStringNotContainsString('rollback', preg_replace('#/\*.*?\*/#s', '', $text) ?? '');
            self::assertStringNotContainsString('lswsctrl', $text);
            self::assertStringNotContainsString('systemctl', $text);
        }
    }

    /**
     * @param list<array{status: int, headers: list<array{0: string, 1: string}>, error: ?string}> $responses the last one repeats
     *
     * @return array{ok: bool, reason: string, attempts: int, observed: string, elapsed: int}
     */
    private function verify(array $responses, int $maxAttempts = 3, int $interval = 2, int $budget = 60, string $url = 'https://testlig.net/'): array
    {
        $this->now = 0.0;
        $this->logs = [];
        $this->sleeps = [];
        $this->requested = [];
        $queue = $responses;

        $verifier = new ExternalRevisionVerifier(
            function (string $requestUrl, int $timeout) use (&$queue): array {
                $this->requested[] = $requestUrl;
                $this->now += 1.0;
                self::assertGreaterThan(0, $timeout);
                $next = \count($queue) > 1 ? array_shift($queue) : $queue[0];

                return $next;
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

        return $verifier->verify($url, self::NEW, self::HOSTS, $maxAttempts, $interval, $budget, 10);
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

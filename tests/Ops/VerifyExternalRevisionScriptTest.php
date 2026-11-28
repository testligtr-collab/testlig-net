<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function Testlig\Deploy\parseHeaderDump;

require_once \dirname(__DIR__, 2).'/deploy/vds/verify-external-revision.php';

final class VerifyExternalRevisionScriptTest extends TestCase
{
    private const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    public function testHeaderDumpOfASingleResponseIsParsed(): void
    {
        $parsed = parseHeaderDump("HTTP/2 200 \r\ncontent-type: text/html\r\nx-testlig-release: ".self::SHA."\r\n\r\n");

        self::assertNotNull($parsed);
        self::assertSame(200, $parsed['status']);
        self::assertContains(['x-testlig-release', self::SHA], $parsed['headers']);
    }

    public function testOnlyTheLastResponseBlockCounts(): void
    {
        $dump = "HTTP/1.1 301 Moved\r\nLocation: https://www.testlig.net/\r\nX-Testlig-Release: old\r\n\r\nHTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n";

        $parsed = parseHeaderDump($dump);

        self::assertNotNull($parsed);
        self::assertSame(200, $parsed['status']);
        self::assertSame([['Content-Type', 'text/html']], $parsed['headers']);
    }

    #[DataProvider('unreadableDumps')]
    public function testUnreadableDumpIsRejected(string $dump): void
    {
        self::assertNull(parseHeaderDump($dump));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableDumps(): iterable
    {
        yield 'empty' => [''];
        yield 'garbage' => ["not http\r\n\r\n"];
        yield 'no status code' => ["HTTP/1.1 OK\r\n\r\n"];
    }

    public function testOneSharedTwentyMinuteBudgetWithShortThenSparseChecks(): void
    {
        self::assertSame(1200, \Testlig\Deploy\BUDGET_SECONDS);
        self::assertSame(10, \Testlig\Deploy\REQUEST_TIMEOUT_SECONDS);
        self::assertSame([15, 30, 45, 60], \Testlig\Deploy\DELAY_SCHEDULE_SECONDS);
        self::assertLessThanOrEqual(60, max(\Testlig\Deploy\DELAY_SCHEDULE_SECONDS));
        self::assertSame(['testlig.net', 'www.testlig.net'], \Testlig\Deploy\ALLOWED_HOSTS);
        self::assertStringNotContainsString('MAX_ATTEMPTS', (string) file_get_contents(\dirname(__DIR__, 2).'/deploy/vds/verify-external-revision.php'));
    }

    public function testVerifiedRunPrintsTheVerifiedResultAndExitsZero(): void
    {
        [$exit, $output] = $this->runCheck(static fn (): array => ['status' => 200, 'headers' => [['X-Testlig-Release', self::SHA]], 'error' => null]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('revision_check_result=verified', $output);
        self::assertStringNotContainsString('unverified', $output);
        self::assertStringContainsString('revision_check_summary url=testlig.net/ rounds=1 ', $output);
        self::assertStringContainsString('revision_check_summary url=testlig.net/dersler rounds=1 ', $output);
    }

    public function testUnverifiedRunKeepsTheFailureResultAndExitsOne(): void
    {
        $old = '0000111122223333444455556666777788889999';
        [$exit, $output, $sleeps] = $this->runCheck(static fn (): array => ['status' => 200, 'headers' => [['X-Testlig-Release', $old]], 'error' => null]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('revision_check_result=unverified', $output);
        self::assertStringNotContainsString('revision_check_result=verified', $output);
        self::assertStringContainsString('last_valid_sha='.$old, $output);
        self::assertLessThanOrEqual(60, max(0, ...$sleeps));
        self::assertSame(\Testlig\Deploy\DELAY_SCHEDULE_SECONDS, \array_slice($sleeps, 0, 4));
    }

    /**
     * @param callable(): array{status: int, headers: list<array{0: string, 1: string}>, error: ?string} $response
     *
     * @return array{int, string, list<int>} exit code, printed output, sleeps
     */
    private function runCheck(callable $response): array
    {
        $now = 100.0;
        $sleeps = [];
        $verifier = new \Testlig\Deploy\ExternalRevisionVerifier(
            static function () use (&$now, $response): array {
                $now += 1.0;

                return $response();
            },
            static function (int $seconds) use (&$now, &$sleeps): void {
                $sleeps[] = $seconds;
                $now += $seconds;
            },
            static function () use (&$now): float {
                return $now;
            },
            static function (string $line): void {
                echo $line."\n";
            },
        );

        ob_start();
        $exit = \Testlig\Deploy\runVerification(['verify', self::SHA, 'https://testlig.net/', 'https://testlig.net/dersler'], $verifier);
        $output = (string) ob_get_clean();

        return [$exit, $output, $sleeps];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('invalidUsage')]
    public function testInvalidUsageExitsWithTwoWithoutNetwork(array $arguments, string $expectedError): void
    {
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/deploy/vds/verify-external-revision.php', ...$arguments]);
        $process->setTimeout(20);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString($expectedError, $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidUsage(): iterable
    {
        yield 'no arguments' => [[], 'revision_check_usage=invalid'];
        yield 'short sha' => [['a1b2c3d', 'https://testlig.net/'], 'revision_check_usage=invalid'];
        yield 'uppercase sha' => [[strtoupper(self::SHA), 'https://testlig.net/'], 'revision_check_usage=invalid'];
        yield 'no url' => [[self::SHA], 'revision_check_usage=invalid'];
        yield 'other host' => [[self::SHA, 'https://example.org/'], 'revision_check_usage=unexpected_host'];
        yield 'lookalike host' => [[self::SHA, 'https://testlig.net.evil.example/'], 'revision_check_usage=unexpected_host'];
        yield 'plain http' => [[self::SHA, 'http://testlig.net/'], 'revision_check_usage=unexpected_host'];
    }
}

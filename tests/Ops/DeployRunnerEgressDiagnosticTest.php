<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The Deploy VDS job logs UTC time, run id/attempt and the runner public IPv4 once,
 * right before the first remote transfer. It is a log-only step: no extra job, no remote
 * connection, no secret, and it never changes the deploy outcome.
 */
final class DeployRunnerEgressDiagnosticTest extends TestCase
{
    private const DIAGNOSTIC_STEP = 'Record runner egress diagnostics (before first remote transfer)';
    private const DNS_STEP = 'Evaluate SSH target DNS (bounded, read-only)';
    private const UPLOAD_STEP = 'Upload bundle and activate release';

    /** @var list<string> */
    private array $cleanupDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanupDirs) as $dir) {
            $this->removeTree($dir);
        }
        $this->cleanupDirs = [];
        parent::tearDown();
    }

    public function testDiagnosticStepSitsRightBeforeTheFirstRemoteTransferInTheSameJob(): void
    {
        $workflow = $this->workflow();
        $jobs = $workflow['jobs'];
        self::assertIsArray($jobs);
        self::assertSame(['pause-notice', 'deploy'], array_keys($jobs), 'diagnostic must not add a job');

        $steps = $this->deploySteps();
        $names = array_map(static fn (array $step): string => (string) ($step['name'] ?? ''), $steps);
        self::assertSame(1, \count(array_keys($names, self::DIAGNOSTIC_STEP, true)));

        $index = array_search(self::DIAGNOSTIC_STEP, $names, true);
        self::assertIsInt($index);
        self::assertSame('Configure SSH client', $names[$index - 1] ?? null);
        self::assertSame(self::DNS_STEP, $names[$index + 1] ?? null);
        self::assertSame(self::UPLOAD_STEP, $names[$index + 2] ?? null);

        // The ssh-agent and client-config steps never connect, so the upload step is the first remote transfer.
        foreach (\array_slice($steps, 0, $index + 2) as $earlier) {
            $run = (string) ($earlier['run'] ?? '');
            self::assertStringNotContainsString('gha-deploy-once.sh', $run);
            self::assertDoesNotMatchRegularExpression('/^\s*(?:ssh|scp|rsync|sftp)\s/m', $run);
        }
        $upload = $steps[$index + 2];
        self::assertStringContainsString('gha-deploy-once.sh', (string) ($upload['run'] ?? ''));
        self::assertSame('${{ steps.rev.outputs.sha }}', $upload['env']['DEPLOY_SHA'] ?? null);

        $pause = $jobs['pause-notice'];
        self::assertIsArray($pause);
        self::assertStringNotContainsString('ipify', (string) json_encode($pause));
    }

    public function testDiagnosticStepIsLogOnlyAndLeaksNothing(): void
    {
        $step = $this->diagnosticStep();

        self::assertTrue($step['continue-on-error'] ?? null);
        self::assertArrayNotHasKey('uses', $step);
        self::assertArrayNotHasKey('if', $step);
        self::assertSame(
            ['DIAG_RUN_ID' => '${{ github.run_id }}', 'DIAG_RUN_ATTEMPT' => '${{ github.run_attempt }}'],
            $step['env'] ?? null,
        );

        $run = $step['run'] ?? null;
        self::assertIsString($run);
        self::assertStringContainsString('curl -4fsS --max-time 5', $run);
        self::assertStringContainsString('https://api.ipify.org', $run);
        self::assertSame(1, substr_count($run, 'curl '));
        self::assertStringContainsString('date -u +%Y-%m-%dT%H:%M:%SZ', $run);
        self::assertStringContainsString('runner public IPv4 alınamadı', $run);
        self::assertStringContainsString('exit 0', $run);

        foreach (['secrets.', 'VDS_', 'SSH_AUTH_SOCK', 'ssh-add', 'printenv', 'set -x', 'set -o xtrace', 'GITHUB_ENV', 'GITHUB_OUTPUT'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $run);
        }
        self::assertDoesNotMatchRegularExpression('/^\s*(?:env|export|declare|set|ssh|scp|rsync|sftp)\s*$/m', $run);
        self::assertDoesNotMatchRegularExpression('/^\s*(?:ssh|scp|rsync|sftp)\s/m', $run);
        self::assertDoesNotMatchRegularExpression('/\b(?:until|while|sleep|retry)\b/', $run);
    }

    /**
     * @return iterable<string, array{string, int, bool}>
     */
    public static function curlResults(): iterable
    {
        yield 'valid ipv4' => ['203.0.113.7', 0, true];
        yield 'valid ipv4 with trailing newline' => ["203.0.113.7\n", 0, true];
        yield 'valid ipv4 with CRLF' => ["198.51.100.250\r\n", 0, true];
        yield 'empty body' => ['', 0, false];
        yield 'octet above 255' => ['999.1.1.1', 0, false];
        yield 'three octets' => ['1.2.3', 0, false];
        yield 'leading zero octet' => ['01.2.3.4', 0, false];
        yield 'html wrapper' => ['<html>1.2.3.4</html>', 0, false];
        yield 'two addresses' => ["1.2.3.4\n5.6.7.8", 0, false];
        yield 'ipv6' => ['2001:db8::1', 0, false];
        yield 'curl http error with valid body' => ['203.0.113.7', 22, false];
        yield 'curl timeout' => ['', 28, false];
    }

    #[DataProvider('curlResults')]
    public function testDiagnosticScriptLogsOneValidIpv4OrReportsFailureWithoutFailing(string $body, int $curlStatus, bool $expectIp): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX bash required to execute the workflow step');
        }

        $dir = $this->tempDir('egress-diag');
        $binDir = $dir.'/bin';
        self::assertTrue(mkdir($binDir, 0755));
        $script = $dir.'/step.sh';
        $run = $this->diagnosticStep()['run'] ?? null;
        self::assertIsString($run);
        file_put_contents($script, "#!/usr/bin/env bash\n".$run."\n");
        file_put_contents($dir.'/body', $body);
        file_put_contents($dir.'/status', (string) $curlStatus);
        file_put_contents(
            $binDir.'/curl',
            "#!/usr/bin/env bash\nprintf '%s ' \"\$@\" > \"\$STUB_DIR/args\"\ncat \"\$STUB_DIR/body\"\nexit \"\$(cat \"\$STUB_DIR/status\")\"\n",
        );
        chmod($binDir.'/curl', 0755);

        // The step also calls the read-only target helper; give it the real script and a stub client config.
        $helperDir = $dir.'/workflow-src/deploy/vds';
        self::assertTrue(mkdir($helperDir, 0755, true));
        self::assertTrue(copy($this->root().'/deploy/vds/gha-ssh-target-diag.sh', $helperDir.'/gha-ssh-target-diag.sh'));
        file_put_contents($binDir.'/ssh', "#!/usr/bin/env bash\nprintf 'hostname 203.0.113.9\\nport 22\\n'\n");
        chmod($binDir.'/ssh', 0755);

        $basePath = getenv('PATH');
        $process = new Process(
            ['bash', '-e', $script],
            $dir,
            [
                'PATH' => $binDir.':'.(\is_string($basePath) && '' !== $basePath ? $basePath : '/usr/local/bin:/usr/bin:/bin'),
                'STUB_DIR' => $dir,
                'DIAG_RUN_ID' => '45442012414',
                'DIAG_RUN_ATTEMPT' => '2',
                'SECRET_SENTINEL' => 'must-never-be-printed',
            ],
        );
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        self::assertSame(0, $process->getExitCode(), $output);
        self::assertMatchesRegularExpression('/^runner_egress_diagnostic_utc=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/m', $output);
        self::assertStringContainsString('runner_egress_run_id=45442012414 run_attempt=2', $output);
        self::assertSame('-4fsS --max-time 5 --proto =https https://api.ipify.org ', file_get_contents($dir.'/args'));
        self::assertStringNotContainsString('must-never-be-printed', $output);
        self::assertStringContainsString('ssh_target_kind=ipv4', $output);
        self::assertStringContainsString('ssh_target_port=22', $output);
        self::assertStringContainsString('ssh_target_matches_expected=no', $output);
        self::assertStringNotContainsString('203.0.113.9', $output);

        if ($expectIp) {
            self::assertSame(1, preg_match_all('/^runner_egress_public_ipv4=((?:0|[1-9]\d{0,2})(?:\.(?:0|[1-9]\d{0,2})){3})$/m', $output, $matches));
            self::assertSame(trim($body), $matches[1][0]);
            self::assertStringNotContainsString('runner public IPv4 alınamadı', $output);
            self::assertStringContainsString('kesin kaynak adres olarak doğrulanmadı', $output);
        } else {
            self::assertStringContainsString('runner public IPv4 alınamadı', $output);
            self::assertStringNotContainsString('runner_egress_public_ipv4=', $output);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(): array
    {
        $parsed = Yaml::parseFile($this->root().'/.github/workflows/deploy.yml');
        self::assertIsArray($parsed);
        self::assertIsArray($parsed['jobs'] ?? null);

        return $parsed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deploySteps(): array
    {
        $jobs = $this->workflow()['jobs'];
        self::assertIsArray($jobs);
        $deploy = $jobs['deploy'] ?? null;
        self::assertIsArray($deploy);
        $steps = $deploy['steps'] ?? null;
        self::assertIsArray($steps);

        $normalized = [];
        foreach ($steps as $step) {
            self::assertIsArray($step);
            $normalized[] = $step;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnosticStep(): array
    {
        foreach ($this->deploySteps() as $step) {
            if (self::DIAGNOSTIC_STEP === ($step['name'] ?? null)) {
                return $step;
            }
        }
        self::fail('diagnostic step missing');
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.$prefix.'-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0700));
        $this->cleanupDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $this->removeTree($path.\DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($path);
    }

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}

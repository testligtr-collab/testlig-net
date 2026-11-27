<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Deploy VDS logs which target the OpenSSH client would use (kind, port, match with the expected
 * IPv4) and, for a hostname target, one time-limited DNS summary. Both read client configuration or
 * the resolver only: no ssh/scp connection, no raw hostname, no resolved address, no secret.
 */
final class DeploySshTargetDiagnosticTest extends TestCase
{
    private const EGRESS_STEP = 'Record runner egress diagnostics (before first remote transfer)';
    private const DNS_STEP = 'Evaluate SSH target DNS (bounded, read-only)';
    private const UPLOAD_STEP = 'Upload bundle and activate release';
    private const EXPECTED = '212.87.197.196';
    private const SCRIPT = 'deploy/vds/gha-ssh-target-diag.sh';

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

    public function testEgressStepRunsTheSummaryAndASeparateBoundedDnsStepFollowsIt(): void
    {
        $steps = $this->deploySteps();
        $names = array_map(static fn (array $step): string => (string) ($step['name'] ?? ''), $steps);
        $egress = array_search(self::EGRESS_STEP, $names, true);
        self::assertIsInt($egress);
        self::assertSame(self::DNS_STEP, $names[$egress + 1] ?? null);
        self::assertSame(self::UPLOAD_STEP, $names[$egress + 2] ?? null);
        self::assertSame(1, \count(array_keys($names, self::DNS_STEP, true)));

        $summary = (string) ($steps[$egress]['run'] ?? '');
        self::assertSame(1, substr_count($summary, 'gha-ssh-target-diag.sh'));
        self::assertStringContainsString('bash workflow-src/deploy/vds/gha-ssh-target-diag.sh summary', $summary);
        self::assertLessThan(
            strpos($summary, 'exit 0') ?: \PHP_INT_MAX,
            strpos($summary, 'gha-ssh-target-diag.sh summary') ?: \PHP_INT_MAX,
            'the helper call must stay before the final exit 0',
        );
        self::assertTrue($steps[$egress]['continue-on-error'] ?? null);

        $dns = $steps[$egress + 1];
        self::assertTrue($dns['continue-on-error'] ?? null);
        self::assertSame(1, $dns['timeout-minutes'] ?? null);
        self::assertArrayNotHasKey('uses', $dns);
        self::assertArrayNotHasKey('if', $dns);
        self::assertArrayNotHasKey('env', $dns, 'the DNS step needs no secret or expression');
        self::assertSame('bash workflow-src/deploy/vds/gha-ssh-target-diag.sh dns', $dns['run'] ?? null);

        // The first remote transfer is still the upload step; nothing before it connects.
        foreach (\array_slice($steps, 0, $egress + 2) as $earlier) {
            $run = (string) ($earlier['run'] ?? '');
            self::assertStringNotContainsString('gha-deploy-once.sh', $run);
            self::assertDoesNotMatchRegularExpression('/^\s*(?:ssh|scp|rsync|sftp)\s/m', $run);
        }
        self::assertStringContainsString('gha-deploy-once.sh', (string) ($steps[$egress + 2]['run'] ?? ''));
        self::assertSame('${{ steps.rev.outputs.sha }}', $steps[$egress + 2]['env']['DEPLOY_SHA'] ?? null);
    }

    public function testHelperReadsClientConfigOnlyAndNeverConnects(): void
    {
        $script = (string) file_get_contents($this->root().'/'.self::SCRIPT);

        self::assertSame(1, preg_match_all('/\bssh\s+-G\s+testlig-vds\b/', $script));
        self::assertDoesNotMatchRegularExpression('/\bssh\s+(?!-G\b)/', $script);
        foreach (['scp ', 'sftp', 'rsync', 'ssh-add', 'ssh-keyscan', 'ssh-agent', 'ControlMaster', 'ControlPath', ' -O ', 'nc ', '/dev/tcp', 'curl ', 'ping ', 'nslookup', 'dig '] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $script);
        }
        self::assertStringNotContainsString('set -x', $script);
        self::assertStringNotContainsString('printenv', $script);
        self::assertDoesNotMatchRegularExpression('/\b(?:until|sleep|retry)\b/', $script);
        self::assertStringContainsString('timeout "$DNS_TIMEOUT_SECONDS" getent ahosts', $script);
        self::assertStringContainsString('exit 0', $script);
        // Raw values must never reach an output command.
        self::assertDoesNotMatchRegularExpression('/^\s*echo\b[^\n]*\$\{?(?:TARGET_HOST|host|resolved|config|counts|addr|a)\b/m', $script);
        self::assertSame(self::EXPECTED, $this->expectedFromScript($script));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function summaryCases(): iterable
    {
        // client config, expected kind, expected port, expected match
        yield 'expected literal on port 22' => ["hostname 212.87.197.196\nport 22\n", 'ipv4', '22', 'yes'];
        yield 'other literal ipv4' => ["hostname 203.0.113.9\nport 22\n", 'ipv4', '22', 'no'];
        yield 'near miss literal' => ["hostname 212.87.197.197\nport 22\n", 'ipv4', '22', 'no'];
        yield 'expected literal on another port' => ["hostname 212.87.197.196\nport 2222\n", 'ipv4', '2222', 'yes'];
        yield 'ipv6 literal' => ["hostname 2001:db8::7\nport 22\n", 'ipv6', '22', 'no'];
        yield 'hostname' => ["hostname vds.secret-host.example\nport 22\n", 'hostname', '22', 'not_evaluated'];
        yield 'single label hostname' => ["hostname vdsbox\nport 22\n", 'hostname', '22', 'not_evaluated'];
        yield 'invalid ipv4 octet' => ["hostname 999.1.1.1\nport 22\n", 'other', '22', 'not_evaluated'];
        yield 'leading zero octet is not the expected literal' => ["hostname 212.087.197.196\nport 22\n", 'other', '22', 'not_evaluated'];
        yield 'missing hostname line' => ["port 22\n", 'unreadable', '22', 'not_evaluated'];
        yield 'missing port line' => ["hostname 212.87.197.196\n", 'ipv4', 'unreadable', 'yes'];
        yield 'port zero' => ["hostname 212.87.197.196\nport 0\n", 'ipv4', 'unreadable', 'yes'];
        yield 'port too large' => ["hostname 212.87.197.196\nport 70000\n", 'ipv4', 'unreadable', 'yes'];
        yield 'port not numeric' => ["hostname 212.87.197.196\nport ssh\n", 'ipv4', 'unreadable', 'yes'];
        yield 'empty config' => ['', 'unreadable', 'unreadable', 'not_evaluated'];
    }

    #[DataProvider('summaryCases')]
    public function testSummaryLogsKindPortAndMatchOnly(string $config, string $kind, string $port, string $matches): void
    {
        $this->requirePosixBash();

        $run = $this->runHelper('summary', $config, 0, '', 'secret-host');
        self::assertSame(0, $run['exit'], $run['output']);
        self::assertStringContainsString('ssh_target_kind='.$kind."\n", $run['output']);
        self::assertStringContainsString('ssh_target_port='.$port."\n", $run['output']);
        self::assertStringContainsString('ssh_target_matches_expected='.$matches."\n", $run['output']);
        self::assertStringNotContainsString('secret-host', $run['output']);
        self::assertStringNotContainsString('identityfile', $run['output']);
        // The expected literal is a fixed constant of the script and is not echoed back.
        self::assertStringNotContainsString(self::EXPECTED, $run['output']);
        self::assertStringNotContainsString('203.0.113.9', $run['output']);
        self::assertStringNotContainsString('2001:db8', $run['output']);
        self::assertStringNotContainsString('vdsbox', $run['output']);
        self::assertStringNotContainsString('vds.secret-host.example', $run['output']);
    }

    public function testSummaryStaysSilentAboutOtherConfigValuesAndSurvivesAClientFailure(): void
    {
        $this->requirePosixBash();

        $config = "user deploy-user\nhostname 212.87.197.196\nport 22\nidentityfile /home/runner/.ssh/id_secret\nproxycommand none\n";
        $run = $this->runHelper('summary', $config, 0, '', 'secret-host');
        self::assertSame(0, $run['exit']);
        self::assertStringNotContainsString('deploy-user', $run['output']);
        self::assertStringNotContainsString('id_secret', $run['output']);

        $failed = $this->runHelper('summary', "hostname 212.87.197.196\nport 22\n", 255, '', 'secret-host');
        self::assertSame(0, $failed['exit'], $failed['output']);
        self::assertStringContainsString('ssh_target_kind=unreadable', $failed['output']);
        self::assertStringContainsString('ssh_target_matches_expected=not_evaluated', $failed['output']);
    }

    public function testUnknownModeStillExitsZero(): void
    {
        $this->requirePosixBash();

        $run = $this->runHelper('bogus', "hostname 212.87.197.196\nport 22\n", 0, '', 'secret-host');
        self::assertSame(0, $run['exit']);
        self::assertStringContainsString('ssh_target_diagnostic=unknown_mode', $run['output']);
    }

    /**
     * @return iterable<string, array{string, int, string, string, string, string, string, string}>
     */
    public static function dnsCases(): iterable
    {
        $host = "hostname vds.secret-host.example\nport 22\n";
        $rows = static fn (string ...$addresses): string => implode('', array_map(
            static fn (string $address): string => $address."  STREAM vds.secret-host.example\n".$address."  DGRAM\n".$address."  RAW\n",
            $addresses,
        ));

        // config, getent exit, getent stdout, expected dns, v4 count, v6 count, includes, only
        yield 'only the expected address' => [$host, 0, $rows('212.87.197.196'), 'resolved', '1', '0', 'yes', 'yes'];
        yield 'expected plus another ipv4' => [$host, 0, $rows('212.87.197.196', '203.0.113.50'), 'resolved', '2', '0', 'yes', 'no'];
        yield 'different address only' => [$host, 0, $rows('203.0.113.50'), 'resolved', '1', '0', 'no', 'no'];
        yield 'expected plus ipv6' => [$host, 0, $rows('2001:db8::9', '212.87.197.196'), 'resolved', '1', '1', 'yes', 'no'];
        yield 'ipv6 only' => [$host, 0, $rows('2001:db8::9'), 'resolved', '0', '1', 'no', 'no'];
        yield 'ipv4 mapped expected' => [$host, 0, $rows('::ffff:212.87.197.196'), 'resolved', '1', '0', 'yes', 'yes'];
        yield 'resolver not found' => [$host, 2, '', 'failed', '', '', '', ''];
        yield 'resolver missing' => [$host, 127, '', 'failed', '', '', '', ''];
        yield 'empty answer' => [$host, 0, '', 'empty', '', '', '', ''];
    }

    #[DataProvider('dnsCases')]
    public function testDnsStepSummarisesCountsWithoutPrintingNamesOrAddresses(
        string $config,
        int $getentExit,
        string $getentOut,
        string $expectedDns,
        string $v4,
        string $v6,
        string $includes,
        string $only,
    ): void {
        $this->requirePosixBash();

        $run = $this->runHelper('dns', $config, 0, $getentOut, 'secret-host', $getentExit);
        self::assertSame(0, $run['exit'], $run['output']);
        self::assertStringContainsString('ssh_target_dns='.$expectedDns, $run['output']);
        if ('resolved' === $expectedDns) {
            self::assertStringContainsString('ssh_target_dns_ipv4_count='.$v4."\n", $run['output']);
            self::assertStringContainsString('ssh_target_dns_ipv6_count='.$v6."\n", $run['output']);
            self::assertStringContainsString('ssh_target_dns_includes_expected='.$includes."\n", $run['output']);
            self::assertStringContainsString('ssh_target_dns_only_expected='.$only."\n", $run['output']);
            self::assertStringContainsString('tek başına kanıtlamaz', $run['output']);
        } else {
            self::assertStringNotContainsString('ssh_target_dns_ipv4_count', $run['output']);
        }
        foreach (['secret-host', 'vds.secret-host.example', self::EXPECTED, '203.0.113.50', '2001:db8', 'STREAM'] as $leak) {
            self::assertStringNotContainsString($leak, $run['output']);
        }
        self::assertSame('ahosts vds.secret-host.example', file_get_contents($run['dir'].'/getent_arg'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonHostnameTargets(): iterable
    {
        yield 'literal expected ipv4' => ["hostname 212.87.197.196\nport 22\n", 'ipv4'];
        yield 'literal other ipv4' => ["hostname 203.0.113.9\nport 22\n", 'ipv4'];
        yield 'ipv6 literal' => ["hostname 2001:db8::7\nport 22\n", 'ipv6'];
        yield 'unreadable' => ['', 'unreadable'];
    }

    #[DataProvider('nonHostnameTargets')]
    public function testDnsStepDoesNotResolveLiteralOrUnreadableTargets(string $config, string $kind): void
    {
        $this->requirePosixBash();

        $run = $this->runHelper('dns', $config, 0, "203.0.113.50 STREAM x\n", 'secret-host');
        self::assertSame(0, $run['exit'], $run['output']);
        self::assertStringContainsString('ssh_target_dns=not_applicable kind='.$kind, $run['output']);
        self::assertFileDoesNotExist($run['dir'].'/getent_arg', 'no resolver lookup for a literal or unreadable target');
    }

    public function testDnsLookupIsTimeLimitedAndReportsTimeout(): void
    {
        $this->requirePosixBash();

        $started = microtime(true);
        $run = $this->runHelper('dns', "hostname vds.secret-host.example\nport 22\n", 0, '', 'secret-host', 0, 'hang', ['TARGET_DIAG_DNS_TIMEOUT' => '1']);
        $elapsed = microtime(true) - $started;

        self::assertSame(0, $run['exit'], $run['output']);
        self::assertStringContainsString('ssh_target_dns=timeout limit_seconds=1', $run['output']);
        self::assertLessThan(10.0, $elapsed, 'the lookup must be cut off by the time limit');
        self::assertStringNotContainsString('secret-host', $run['output']);
    }

    /**
     * Runs the real helper with a stub `ssh` (prints the given client config) and a stub `getent`.
     *
     * @param array<string, string> $extraEnv
     *
     * @return array{exit: int|null, output: string, dir: string}
     */
    private function runHelper(
        string $mode,
        string $config,
        int $sshExit,
        string $getentOut,
        string $sentinel,
        int $getentExit = 0,
        string $getentBehaviour = 'normal',
        array $extraEnv = [],
    ): array {
        $dir = $this->tempDir('ssh-target-diag');
        $binDir = $dir.'/bin';
        self::assertTrue(mkdir($binDir, 0755));
        file_put_contents($dir.'/ssh_config', $config);
        file_put_contents($dir.'/ssh_exit', (string) $sshExit);
        file_put_contents($dir.'/getent_out', $getentOut);
        file_put_contents($dir.'/getent_exit', (string) $getentExit);

        // The stub ssh must be called exactly as `ssh -G testlig-vds`; anything else is a failure.
        file_put_contents(
            $binDir.'/ssh',
            "#!/usr/bin/env bash\n[ \"\$*\" = '-G testlig-vds' ] || { echo 'unexpected ssh call' >&2; exit 99; }\ncat \"\$STUB_DIR/ssh_config\"\nexit \"\$(cat \"\$STUB_DIR/ssh_exit\")\"\n",
        );
        $getent = "#!/usr/bin/env bash\nprintf '%s' \"\$*\" > \"\$STUB_DIR/getent_arg\"\n";
        if ('hang' === $getentBehaviour) {
            $getent .= "sleep 30\n";
        }
        $getent .= "cat \"\$STUB_DIR/getent_out\"\nexit \"\$(cat \"\$STUB_DIR/getent_exit\")\"\n";
        file_put_contents($binDir.'/getent', $getent);
        chmod($binDir.'/ssh', 0755);
        chmod($binDir.'/getent', 0755);

        $basePath = getenv('PATH');
        $process = new Process(
            ['bash', $this->root().'/'.self::SCRIPT, $mode],
            $dir,
            $extraEnv + [
                'PATH' => $binDir.':'.(\is_string($basePath) && '' !== $basePath ? $basePath : '/usr/local/bin:/usr/bin:/bin'),
                'STUB_DIR' => $dir,
                'SECRET_SENTINEL' => $sentinel,
            ],
        );
        $process->setTimeout(60);
        $process->run();

        return [
            'exit' => $process->getExitCode(),
            'output' => $process->getOutput().$process->getErrorOutput(),
            'dir' => $dir,
        ];
    }

    private function expectedFromScript(string $script): string
    {
        if (1 !== preg_match('/^EXPECTED_IPV4="([^"]+)"$/m', $script, $match)) {
            self::fail('EXPECTED_IPV4 constant missing from the helper');
        }

        return $match[1];
    }

    private function requirePosixBash(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX bash required to execute the helper');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deploySteps(): array
    {
        $parsed = Yaml::parseFile($this->root().'/.github/workflows/deploy.yml');
        self::assertIsArray($parsed);
        $jobs = $parsed['jobs'] ?? null;
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

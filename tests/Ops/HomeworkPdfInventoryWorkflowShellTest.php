<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the real `run:` block of the SSH step of ops-homework-pdf-inventory.yml, with the same Bash options
 * Actions uses for it (`bash -e {0}`), against a stub `ssh`. The real reporter and ControlMaster helper are
 * used. Nothing connects anywhere.
 */
final class HomeworkPdfInventoryWorkflowShellTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/ops-homework-pdf-inventory.yml';
    private const STEP = 'Run remote read-only inventory and readiness preview';
    private const SHA = '5eafc0496f928c1c9faf1c46796ea55ab51fdd95';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->removeTree($dir);
        }
        $this->dirs = [];
    }

    public function testSshSuccessPublishesSafeCountersSummaryAndCleansUp(): void
    {
        $run = $this->runStep(['stdout' => $this->safeLines('ok'), 'exit' => 0, 'socket' => true]);

        self::assertSame(0, $run['exit'], $run['output']);
        self::assertStringContainsString("ssh_exit_code=0\n", $run['output']);
        self::assertStringContainsString("ssh_result=success\n", $run['output']);
        self::assertStringContainsString("remote_complete=yes\n", $run['output']);
        self::assertStringContainsString("remote_stage=ok\n", $run['output']);
        self::assertStringContainsString("db_declared_bytes=1000\n", $run['output']);
        self::assertStringContainsString("db_physical_bytes=900\n", $run['output']);
        self::assertStringContainsString("report_verdict=pass\n", $run['output']);
        self::assertStringContainsString("summary_write=ok\n", $run['output']);
        self::assertStringContainsString("master_close_exit=0\n", $run['output']);
        self::assertStringContainsString("temp_files_left=no\n", $run['output']);
        self::assertStringContainsString('Expected live release SHA: '.self::SHA, $run['summary']);
        self::assertStringContainsString("orphan_candidate_files=1\n", $run['summary']);
        $this->assertSingleSshAndCleanup($run, true);
    }

    public function testRemoteCommandFailureKeepsAllowedLinesAndStatus(): void
    {
        $lines = [
            'hw_pdf_ops=read-only',
            'release_revision_match=yes',
            'inventory_result=root_unsafe',
            'inventory_exit_code=1',
            'inventory_status=root_unsafe',
            'hw_pdf_ops_complete=yes',
        ];
        $run = $this->runStep(['stdout' => implode("\n", $lines)."\n", 'exit' => 1, 'socket' => true]);

        self::assertSame(1, $run['exit'], 'The step must stay red and must not stop before the report.');
        self::assertStringContainsString("ssh_exit_code=1\n", $run['output']);
        self::assertStringContainsString("ssh_result=remote_command_failed\n", $run['output']);
        self::assertStringContainsString("remote_stage=root_unsafe\n", $run['output']);
        self::assertStringContainsString("inventory_result=root_unsafe\n", $run['output']);
        self::assertStringContainsString("report_verdict=fail_ssh_nonzero\n", $run['output']);
        self::assertStringContainsString("summary_write=ok\n", $run['output']);
        self::assertStringContainsString("master_close_exit=0\n", $run['output']);
        self::assertStringContainsString('inventory_result=root_unsafe', $run['summary']);
        $this->assertSingleSshAndCleanup($run, true);
    }

    public function testRemoteFailureWithoutOutputIsUndeterminedNeverPassOrEmptyStore(): void
    {
        $run = $this->runStep(['stdout' => '', 'exit' => 1, 'socket' => true]);

        self::assertSame(1, $run['exit'], $run['output']);
        self::assertStringContainsString("ssh_result=remote_command_failed\n", $run['output']);
        self::assertStringContainsString("remote_stage=undetermined\n", $run['output']);
        self::assertStringContainsString("remote_stage_note=remote_stage_could_not_be_determined\n", $run['output']);
        self::assertStringContainsString("remote_complete=no\n", $run['output']);
        self::assertStringContainsString('Uzak asama belirlenemedi', $run['summary']);
        foreach (['inventory_result=ok', 'store_empty', 'report_verdict=pass', 'root_missing'] as $needle) {
            self::assertStringNotContainsString($needle, $run['output'], $needle);
            self::assertStringNotContainsString($needle, $run['summary'], $needle);
        }
        $this->assertSingleSshAndCleanup($run, true);
    }

    public function testConnectionFailureIsSeparateAndOpensNoSecondConnectionWithoutSocket(): void
    {
        $run = $this->runStep([
            'stdout' => '',
            'stderr' => "ssh: connect to host secret-host.example port 22: Connection timed out\n",
            'exit' => 255,
            'socket' => false,
        ]);

        self::assertSame(255, $run['exit'], $run['output']);
        self::assertStringContainsString("ssh_exit_code=255\n", $run['output']);
        self::assertStringContainsString("ssh_result=connection_failed\n", $run['output']);
        self::assertStringContainsString("ssh_error_class=connection_timed_out\n", $run['output']);
        self::assertStringContainsString("ssh_stderr_lines=1\n", $run['output']);
        self::assertStringContainsString("remote_stage=undetermined\n", $run['output']);
        self::assertStringContainsString("master_close_exit=0\n", $run['output']);
        self::assertStringNotContainsString('secret-host.example', $run['output']);
        self::assertStringNotContainsString('secret-host.example', $run['summary']);
        // No control socket: the cleanup helper must not open any connection.
        self::assertCount(1, $run['calls'], 'Only the one SSH call; no cleanup connection.');
        $this->assertSingleSshAndCleanup($run, false);
    }

    public function testDisallowedOutputNeverReachesLogOrSummary(): void
    {
        $long = 'orphan_candidate_files='.str_repeat('9', 400);
        $stdout = implode("\n", [
            'hw_pdf_ops=read-only',
            'APP_SECRET=super-secret-value',
            'DB_PASSWORD=super-secret-value',
            'homework_pdf_secret=super-secret-value',
            'homework_pdf_upload_enabled declared=1 token=abc source=shared_env_local',
            "\x1b[31mred\x1b[0m inventory_result=ok",
            'inventory_result=ok'."\x07",
            '::error::injected',
            '::add-mask::super',
            '```',
            'path=/home/testlig.net/shared/.env.local',
            'inventory_result=ok /home/x',
            'SQLSTATE[HY000] Doctrine failure',
            $long,
            'student=student@example.test',
            'disk_files=6',
            'hw_pdf_ops_complete=yes',
        ])."\n";
        $run = $this->runStep(['stdout' => $stdout, 'exit' => 0, 'socket' => true]);

        self::assertSame(0, $run['exit'], $run['output']);
        self::assertStringContainsString("remote_lines_dropped=14\n", $run['output']);
        self::assertStringContainsString("disk_files=6\n", $run['output']);
        foreach (['super-secret-value', 'DB_PASSWORD', 'APP_SECRET', '::error::injected', '::add-mask::', '.env.local', 'student@example.test', 'SQLSTATE', 'Doctrine', '/home/x', "\x1b", "\x07", '99999'] as $needle) {
            self::assertStringNotContainsString($needle, $run['output'], $needle);
            self::assertStringNotContainsString($needle, $run['summary'], $needle);
        }
        self::assertCount(2, array_filter(explode("\n", $run['summary']), static fn (string $line): bool => '```' === $line), 'Only the two fence lines exist.');
        $this->assertSingleSshAndCleanup($run, true);
    }

    public function testSuccessWithoutCompletionMarkerOrWithEmptyOutputDoesNotPass(): void
    {
        $partial = $this->runStep(['stdout' => "hw_pdf_ops=read-only\ninventory_result=ok\n", 'exit' => 0, 'socket' => true]);
        self::assertSame(1, $partial['exit'], $partial['output']);
        self::assertStringContainsString("remote_complete=no\n", $partial['output']);
        self::assertStringContainsString("report_verdict=fail_remote_report_incomplete\n", $partial['output']);

        $empty = $this->runStep(['stdout' => '', 'exit' => 0, 'socket' => true]);
        self::assertSame(1, $empty['exit'], $empty['output']);
        self::assertStringContainsString("remote_complete=no\n", $empty['output']);
        self::assertStringContainsString("remote_stage=undetermined\n", $empty['output']);
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>, int, string}>
     */
    public static function cleanupFailureProvider(): iterable
    {
        yield 'ssh ok, master close fails' => [0, ['close_exit' => 3], 1, 'master_close_exit=3'];
        yield 'ssh failed, master close fails' => [7, ['close_exit' => 3], 7, 'master_close_exit=3'];
        yield 'ssh ok, summary cannot be written' => [0, ['summary_unwritable' => true], 1, 'summary_write=failed'];
        yield 'ssh failed, summary cannot be written' => [7, ['summary_unwritable' => true], 7, 'summary_write=failed'];
        yield 'ssh ok, temp removal fails' => [0, ['rm_fails' => true], 1, 'temp_cleanup_exit=1'];
        yield 'ssh failed, temp removal fails' => [7, ['rm_fails' => true], 7, 'temp_cleanup_exit=1'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('cleanupFailureProvider')]
    public function testCleanupOrSummaryFailureNeverHidesOriginalResultOrFakesSuccess(int $sshExit, array $options, int $expectedExit, string $marker): void
    {
        $stdout = 0 === $sshExit ? $this->safeLines('ok') : "hw_pdf_ops=read-only\ninventory_result=failed\n";
        $run = $this->runStep(array_merge(['stdout' => $stdout, 'exit' => $sshExit, 'socket' => true], $options));

        self::assertSame($expectedExit, $run['exit'], $run['output']);
        self::assertStringContainsString($marker, $run['output']);
        self::assertStringContainsString('ssh_exit_code='.$sshExit."\n", $run['output']);
        if (0 === $sshExit && !($options['summary_unwritable'] ?? false)) {
            self::assertStringContainsString('::error::Mandatory cleanup failed', $run['output']);
        }
        self::assertCount(1, array_filter($run['calls'], static fn (string $call): bool => !str_contains($call, '-O exit')));
    }

    public function testWorkflowShellBlockRunsUnderErrexitAndKeepsItOn(): void
    {
        $block = $this->stepRun();
        self::assertStringNotContainsString('set +e', $block);
        self::assertStringNotContainsString('set -e', $block);
        self::assertStringNotContainsString('|| true', $block);
        self::assertStringNotContainsString('continue-on-error', (string) file_get_contents($this->root().'/'.self::WORKFLOW));
        self::assertMatchesRegularExpression('/^ssh testlig-vds .* \|\| ssh_status=\$\?$/m', $block);
        self::assertStringContainsString('trap cleanup EXIT', $block);
        self::assertLessThan(strpos($block, 'ssh testlig-vds'), strpos($block, 'trap cleanup EXIT'));
        self::assertStringNotContainsString('cat "$out"', $block, 'The raw remote output is never printed.');
        self::assertStringNotContainsString('cat "$err"', $block);
    }

    public function testReporterHelperIsLocalOnlyAndPrintsNoRawFiles(): void
    {
        $script = (string) file_get_contents($this->root().'/deploy/vds/gha-homework-pdf-inventory-report.sh');
        self::assertDoesNotMatchRegularExpression('/\b(ssh|scp|sftp|rsync|curl|wget|nc)\s/', preg_replace('/^\s*#.*$/m', '', $script) ?? '');
        self::assertDoesNotMatchRegularExpression('/\bcat\b/', $script);
        self::assertDoesNotMatchRegularExpression('/\|\|\s*true/', $script);
        self::assertStringNotContainsString('eval ', $script);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{exit: int, output: string, summary: string, calls: list<string>, stdin: string, left: list<string>, forbidden: string}
     */
    private function runStep(array $options): array
    {
        $work = str_replace('\\', '/', sys_get_temp_dir()).'/hwinv-shell-'.bin2hex(random_bytes(4));
        $this->dirs[] = $work;
        foreach (['deploy/vds', 'bin', 'home/.ssh', 'tmp'] as $dir) {
            mkdir($work.'/'.$dir, 0777, true);
        }
        foreach (['gha-homework-pdf-inventory-remote.sh', 'gha-homework-pdf-inventory-report.sh', 'gha-ssh-close-master.sh'] as $file) {
            copy($this->root().'/deploy/vds/'.$file, $work.'/deploy/vds/'.$file);
        }
        if ($options['socket'] ?? false) {
            file_put_contents($work.'/home/.ssh/cm-testlig-vds', '');
        }
        file_put_contents($work.'/bin/ssh', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$STUB_CALLS"
case " $* " in
  *" -O exit "*) exit "${STUB_CLOSE_EXIT:-0}" ;;
esac
cat > "$STUB_STDIN_COPY"
if [ -n "${STUB_STDERR_FILE:-}" ]; then cat "$STUB_STDERR_FILE" >&2; fi
if [ -n "${STUB_OUT_FILE:-}" ]; then cat "$STUB_OUT_FILE"; fi
exit "${STUB_EXIT:-0}"
BASH);
        foreach (['scp', 'sftp', 'rsync'] as $forbidden) {
            file_put_contents($work.'/bin/'.$forbidden, "#!/usr/bin/env bash\nprintf '%s\\n' \"$0 \$*\" >> \"\$STUB_FORBIDDEN\"\nexit 0\n");
        }
        file_put_contents($work.'/bin/rm', <<<'BASH'
#!/usr/bin/env bash
if [ "${STUB_RM_FAILS:-0}" = "1" ]; then
  exit 1
fi
real="$(PATH=/usr/bin:/bin command -v rm)"
exec "$real" "$@"
BASH);
        file_put_contents($work.'/stub.out', (string) ($options['stdout'] ?? ''));
        file_put_contents($work.'/stub.err', (string) ($options['stderr'] ?? ''));
        file_put_contents($work.'/step.sh', $this->stepRun()."\n");
        foreach (['bin/ssh', 'bin/scp', 'bin/sftp', 'bin/rsync', 'bin/rm'] as $executable) {
            chmod($work.'/'.$executable, 0755);
        }

        $summary = $work.'/summary.md';
        if ($options['summary_unwritable'] ?? false) {
            mkdir($summary, 0777, true);
        } else {
            file_put_contents($summary, '');
        }

        $path = \DIRECTORY_SEPARATOR === '\\'
            ? $this->bashPath($work.'/bin').':'.$this->bashPath(\dirname($this->bash())).':/usr/bin:/bin'
            : $work.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin');
        $env = [
            'PATH' => $path,
            'HOME' => $this->bashPath($work.'/home'),
            'RUNNER_TEMP' => $this->bashPath($work.'/tmp'),
            'GITHUB_STEP_SUMMARY' => $this->bashPath($summary),
            'EXPECTED_SHA' => self::SHA,
            'STUB_CALLS' => $this->bashPath($work.'/calls.log'),
            'STUB_STDIN_COPY' => $this->bashPath($work.'/stdin.copy'),
            'STUB_FORBIDDEN' => $this->bashPath($work.'/forbidden.log'),
            'STUB_OUT_FILE' => $this->bashPath($work.'/stub.out'),
            'STUB_STDERR_FILE' => $this->bashPath($work.'/stub.err'),
            'STUB_EXIT' => (string) ($options['exit'] ?? 0),
            'STUB_CLOSE_EXIT' => (string) ($options['close_exit'] ?? 0),
            'STUB_RM_FAILS' => ($options['rm_fails'] ?? false) ? '1' : '0',
        ];
        if (\DIRECTORY_SEPARATOR === '\\') {
            $env['SystemRoot'] = (string) getenv('SystemRoot');
        }

        $pipes = [];
        $process = proc_open([$this->bash(), '-e', 'step.sh'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $work, $env);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $calls = is_file($work.'/calls.log') ? file($work.'/calls.log', \FILE_IGNORE_NEW_LINES) : [];
        self::assertIsArray($calls);
        $left = [];
        foreach (['homework-pdf-inventory.out', 'homework-pdf-inventory.err'] as $name) {
            if (is_file($work.'/tmp/'.$name)) {
                $left[] = $name;
            }
        }

        return [
            'exit' => $exit,
            'output' => str_replace("\r\n", "\n", (string) $stdout.(string) $stderr),
            'summary' => is_file($summary) ? str_replace("\r\n", "\n", (string) file_get_contents($summary)) : '',
            'calls' => array_map(static fn (string $line): string => rtrim($line, "\r"), $calls),
            'stdin' => is_file($work.'/stdin.copy') ? (string) file_get_contents($work.'/stdin.copy') : '',
            'left' => $left,
            'forbidden' => is_file($work.'/forbidden.log') ? (string) file_get_contents($work.'/forbidden.log') : '',
        ];
    }

    /**
     * @param array{exit: int, output: string, summary: string, calls: list<string>, stdin: string, left: list<string>, forbidden: string} $run
     */
    private function assertSingleSshAndCleanup(array $run, bool $socketPresent): void
    {
        $connections = array_values(array_filter($run['calls'], static fn (string $call): bool => !str_contains($call, '-O exit')));
        self::assertCount(1, $connections, 'Exactly one SSH connection, no retry.');
        self::assertStringContainsString('testlig-vds', $connections[0]);
        self::assertStringContainsString('export EXPECTED_SHA='.self::SHA.'; bash -s', $connections[0]);
        self::assertSame(
            (string) file_get_contents($this->root().'/deploy/vds/gha-homework-pdf-inventory-remote.sh'),
            $run['stdin'],
            'The reviewed remote script is what goes over stdin.',
        );
        $closes = array_values(array_filter($run['calls'], static fn (string $call): bool => str_contains($call, '-O exit')));
        self::assertCount($socketPresent ? 1 : 0, $closes, 'ControlMaster cleanup runs only for an existing socket.');
        if ($socketPresent) {
            self::assertStringContainsString('ControlMaster=no', $closes[0]);
        }
        self::assertSame([], $run['left'], 'Temporary files are removed.');
        self::assertSame('', $run['forbidden'], 'No scp, sftp or rsync.');
    }

    private function safeLines(string $result): string
    {
        return implode("\n", [
            'hw_pdf_ops=read-only',
            'hw_pdf_ops_changes=no',
            'release_revision_match=yes',
            'inventory_result='.$result,
            'inventory_exit_code=0',
            'inventory_status=ok',
            'inventory_options=none',
            'orphan_min_age_seconds=86400',
            'db_declared_bytes=1000',
            'db_physical_bytes=900',
            'db_declared_minus_physical_bytes=100',
            'orphan_candidate_files=1',
            'clamd_running_stream_max_length=not_measured',
            'scan_readiness_summary=preview_only gates_unchanged=yes proven=no',
            'hw_pdf_ops_complete=yes',
        ])."\n";
    }

    private function stepRun(): string
    {
        $parsed = Yaml::parseFile($this->root().'/'.self::WORKFLOW);
        self::assertIsArray($parsed);
        $steps = $parsed['jobs']['vds']['steps'] ?? null;
        self::assertIsArray($steps);
        foreach ($steps as $step) {
            if (\is_array($step) && self::STEP === ($step['name'] ?? null)) {
                self::assertIsString($step['run']);
                self::assertArrayNotHasKey('shell', $step, 'The default `bash -e {0}` is what the test reproduces.');
                self::assertArrayNotHasKey('continue-on-error', $step);

                return rtrim($step['run'], "\n");
            }
        }
        self::fail('SSH step not found.');
    }

    private function bash(): string
    {
        $git = 'C:\\Program Files\\Git\\bin\\bash.exe';
        if (\DIRECTORY_SEPARATOR === '\\' && is_file($git)) {
            return $git;
        }

        return 'bash';
    }

    private function bashPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (\DIRECTORY_SEPARATOR === '\\' && 1 === preg_match('/^([A-Za-z]):\/(.*)$/', $path, $matches)) {
            return '/'.strtolower($matches[1]).'/'.$matches[2];
        }

        return $path;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $this->removeTree($path.'/'.$item);
        }
        rmdir($path);
    }

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}

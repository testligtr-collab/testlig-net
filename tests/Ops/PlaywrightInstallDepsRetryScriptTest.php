<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;

final class PlaywrightInstallDepsRetryScriptTest extends TestCase
{
    public function testSuccessOnFirstAttemptCallsInstallerOnce(): void
    {
        $root = $this->fixture();
        $bin = $this->fakeInstaller($root, [
            ['exit' => 0, 'output' => "Installing dependencies...\nok\n"],
        ]);

        $result = $this->runScript($root, $bin);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('playwright-install-deps: ok attempts=1', $result['output']);
        self::assertStringNotContainsString('attempt=2', $result['output']);
        self::assertSame(1, $this->callCount($root));
    }

    public function testRecognizedDnsFailureRetriesOnceAndSucceeds(): void
    {
        $root = $this->fixture();
        $bin = $this->fakeInstaller($root, [
            [
                'exit' => 100,
                'output' => "Err:1 http://azure.archive.ubuntu.com/ubuntu noble/main amd64 fonts-liberation amd64 1:2.1.5-3\n  Temporary failure resolving 'azure.archive.ubuntu.com'\nE: Failed to fetch http://azure.archive.ubuntu.com/ubuntu/pool/main/f/fonts-liberation/fonts-liberation_2.1.5-3_amd64.deb  Temporary failure resolving 'azure.archive.ubuntu.com'\nFailed to install browsers\n",
            ],
            ['exit' => 0, 'output' => "Installing dependencies...\nok\n"],
        ]);

        $result = $this->runScript($root, $bin);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('transient_dns_or_network detected', $result['output']);
        self::assertStringContainsString('playwright-install-deps: ok attempts=2', $result['output']);
        self::assertSame(2, $this->callCount($root));
    }

    public function testExit100AloneDoesNotRetry(): void
    {
        $root = $this->fixture();
        $bin = $this->fakeInstaller($root, [
            ['exit' => 100, 'output' => "Failed to install browsers\nSomething unexplained went wrong\n"],
        ]);

        $result = $this->runScript($root, $bin);

        self::assertSame(100, $result['exit']);
        self::assertStringContainsString('no_retry reason=non_transient_or_unrecognized exit=100', $result['output']);
        self::assertStringNotContainsString('attempt=2', $result['output']);
        self::assertSame(1, $this->callCount($root));
    }

    public function testGpgFailureDoesNotRetry(): void
    {
        $root = $this->fixture();
        $bin = $this->fakeInstaller($root, [
            [
                'exit' => 100,
                'output' => "W: GPG error: http://azure.archive.ubuntu.com/ubuntu noble InRelease: The following signatures were invalid: BADSIG\nE: The repository 'http://azure.archive.ubuntu.com/ubuntu noble InRelease' is not signed.\n",
            ],
        ]);

        $result = $this->runScript($root, $bin);

        self::assertSame(100, $result['exit']);
        self::assertStringContainsString('no_retry reason=non_transient_or_unrecognized', $result['output']);
        self::assertSame(1, $this->callCount($root));
    }

    public function testPackageNotFoundDoesNotRetry(): void
    {
        $root = $this->fixture();
        $bin = $this->fakeInstaller($root, [
            ['exit' => 100, 'output' => "E: Unable to locate package fonts-liberation\n"],
        ]);

        $result = $this->runScript($root, $bin);

        self::assertSame(100, $result['exit']);
        self::assertStringContainsString('no_retry reason=non_transient_or_unrecognized', $result['output']);
        self::assertSame(1, $this->callCount($root));
    }

    public function testSecondTransientFailurePreservesExitCode(): void
    {
        $root = $this->fixture();
        $fail = "Temporary failure resolving 'azure.archive.ubuntu.com'\nE: Failed to fetch http://azure.archive.ubuntu.com/ubuntu/pool/main/f/fonts-liberation/fonts-liberation_2.1.5-3_amd64.deb\n";
        $bin = $this->fakeInstaller($root, [
            ['exit' => 100, 'output' => $fail],
            ['exit' => 100, 'output' => $fail],
        ]);

        $result = $this->runScript($root, $bin);

        self::assertSame(100, $result['exit']);
        self::assertStringContainsString('playwright-install-deps: failed after retry exit=100', $result['output']);
        self::assertSame(2, $this->callCount($root));
    }

    public function testWorkflowSplitsBrowserAndDepsWithoutWithDepsOrContinueOnError(): void
    {
        $ci = (string) file_get_contents($this->root('.github/workflows/ci.yml'));
        $script = (string) file_get_contents($this->root('.github/scripts/playwright-install-deps-with-transient-retry.sh'));

        self::assertStringContainsString('Install Chromium browser', $ci);
        self::assertStringContainsString('npx playwright install chromium', $ci);
        self::assertStringContainsString('Install Chromium system dependencies', $ci);
        self::assertStringContainsString('playwright-install-deps-with-transient-retry.sh', $ci);
        self::assertStringNotContainsString('--with-deps', $ci);
        self::assertStringNotContainsString('continue-on-error', $ci);
        self::assertDoesNotMatchRegularExpression('/uses:\s*\S*retry/i', $ci);
        self::assertStringNotContainsString('sed -i', $script);
        self::assertStringNotContainsString('Acquire::https::Verify-Peer', $script);
        self::assertStringNotContainsString('rm -f /var/lib/dpkg/lock', $script);
        self::assertStringNotContainsString('while ', $script);
        self::assertStringNotContainsString('until ', $script);
        self::assertLessThan(
            strpos($ci, 'Run Chromium suite'),
            strpos($ci, 'Install Chromium system dependencies')
        );
    }

    /**
     * @param list<array{exit: int, output: string}> $responses
     */
    private function fakeInstaller(string $root, array $responses): string
    {
        $binDir = $root.'/bin';
        $respDir = $root.'/responses';
        mkdir($binDir, 0700, true);
        mkdir($respDir, 0700, true);
        foreach ($responses as $index => $response) {
            file_put_contents($respDir.'/'.$index.'.out', $response['output']);
            file_put_contents($respDir.'/'.$index.'.exit', (string) $response['exit']);
        }

        $rootBash = $this->bashPath($root);
        $script = <<<SH
#!/usr/bin/env bash
set -u
root={$this->bashQuote($rootBash)}
state="\${root}/state"
resp="\${root}/responses"
mkdir -p "\${state}"
count_file="\${state}/calls"
if [[ -f "\${count_file}" ]]; then
  count=\$(cat "\${count_file}")
else
  count=0
fi
echo "\$((count + 1))" > "\${count_file}"
idx=\${count}
out="\${resp}/\${idx}.out"
code="\${resp}/\${idx}.exit"
if [[ ! -f "\${out}" || ! -f "\${code}" ]]; then
  echo "fake installer missing response for attempt \${idx}" >&2
  exit 99
fi
cat "\${out}"
exit "\$(cat "\${code}")"
SH;
        $path = $binDir.'/fake-playwright-install-deps';
        file_put_contents($path, $script);
        chmod($path, 0700);

        return $path;
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function runScript(string $root, string $bin): array
    {
        $bash = $this->bashBinary();
        $script = $this->bashPath($this->root('.github/scripts/playwright-install-deps-with-transient-retry.sh'));
        $binPath = $this->bashPath($bin);
        $env = [
            'PLAYWRIGHT_INSTALL_DEPS_BIN' => $binPath,
            'PLAYWRIGHT_INSTALL_DEPS_RETRY_SLEEP_SECONDS' => '0',
            'PATH' => getenv('PATH') ?: '',
            'SystemRoot' => getenv('SystemRoot') ?: '',
            'TMP' => getenv('TMP') ?: sys_get_temp_dir(),
            'TEMP' => getenv('TEMP') ?: sys_get_temp_dir(),
            'TMPDIR' => getenv('TMPDIR') ?: sys_get_temp_dir(),
        ];
        $pipes = [];
        $process = proc_open(
            [$bash, $script, 'chromium'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'exit' => $exit,
            'output' => $stdout.$stderr,
        ];
    }

    private function callCount(string $root): int
    {
        $file = $root.'/state/calls';
        if (!is_file($file)) {
            return 0;
        }

        return (int) trim((string) file_get_contents($file));
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir().'/testlig-pw-deps-'.bin2hex(random_bytes(8));
        mkdir($root.'/state', 0700, true);

        return $root;
    }

    private function root(string $path): string
    {
        return \dirname(__DIR__, 2).\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $path);
    }

    private function bashPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (1 === preg_match('/^([A-Za-z]):\/(.*)$/', $normalized, $matches)) {
            return '/'.strtolower($matches[1]).'/'.$matches[2];
        }

        return $normalized;
    }

    private function bashQuote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private function bashBinary(): string
    {
        foreach (['C:\\Program Files\\Git\\bin\\bash.exe', 'bash'] as $candidate) {
            if ('bash' !== $candidate && !is_file($candidate)) {
                continue;
            }
            $pipes = [];
            $process = @proc_open([$candidate, '-c', 'echo ok'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!\is_resource($process)) {
                continue;
            }
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            if (\is_string($stdout) && str_contains($stdout, 'ok')) {
                return $candidate;
            }
        }
        self::fail('bash is required to run the Playwright install-deps retry fixture');
    }
}

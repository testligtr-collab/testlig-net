<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;

final class QuestionPackageImportStdinProtocolTest extends TestCase
{
    public function testMetacharacterSecretIsReadNotExecutedAndReachesImporterScript(): void
    {
        $result = $this->runProtocol('dry-run', '', $this->package(), $this->fakeSecret(), $this->probeScript());
        self::assertSame(0, $result['exit']);
        self::assertSame('0', $result['pwned']);
        self::assertSame('1', $result['reached']);
        self::assertStringContainsString('probe_ok', $result['stdout']);
        self::assertStringNotContainsString($this->fakeSecret(), $result['stdout']);
        self::assertStringNotContainsString($this->fakeSecret(), $result['commandLine']);
    }

    public function testEmptySecretFailsBeforeImporterScript(): void
    {
        $result = $this->runProtocol('dry-run', '', $this->package(), '', $this->probeScript());
        self::assertNotSame(0, $result['exit']);
        self::assertSame('0', $result['reached']);
        self::assertStringContainsString('Actor is not available.', $result['stdout']);
    }

    public function testUnknownPackageStopsBeforeImporter(): void
    {
        $result = $this->runValidation('dry-run', '', 'data/content/evil');
        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('package is not allowlisted', $result['stdout']);
        self::assertSame('0', $result['importer']);
    }

    public function testApplyWithoutFingerprintStopsBeforeImporter(): void
    {
        $result = $this->runValidation('apply', '', $this->package());
        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('apply requires the dry-run plan fingerprint', $result['stdout']);
        self::assertSame('0', $result['importer']);
    }

    public function testAllowlistedPackagePassesValidationWithoutImporter(): void
    {
        $result = $this->runValidation('dry-run', '', $this->package());
        self::assertSame(0, $result['exit']);
        self::assertSame('0', $result['importer']);
    }

    /**
     * @return array{exit: int, stdout: string, pwned: string, reached: string, commandLine: string}
     */
    private function runProtocol(string $mode, string $plan, string $package, string $secret, string $remaining): array
    {
        $bash = $this->bashBinary();
        self::assertNotNull($bash);
        $work = $this->workDir();
        copy($this->root().'/deploy/vds/gha-question-package-import-entry.sh', $work.'/entry.sh');
        file_put_contents($work.'/remaining.sh', str_replace(["\r\n", "\r", '__REACHED__'], ["\n", "\n", './reached'], $remaining));
        file_put_contents($work.'/secret.in', $secret);
        $script = <<<'BASH'
set +e
export MODE="$1"
export PLAN="$2"
export PACKAGE="$3"
SECRET=$(cat ./secret.in)
{
  printf 'export MODE=%s; export PLAN=%s; export PACKAGE=%s\n' "$(printf %q "$MODE")" "$(printf %q "$PLAN")" "$(printf %q "$PACKAGE")"
  cat ./entry.sh
} > ./cmdline.txt
bash ./entry.sh < <(
  printf '%s\n' "$SECRET"
  cat ./remaining.sh
)
status=$?
printf 'EXIT:%s\n' "$status"
if [ -f ./tl-secret-executed ]; then printf 'PWNED:1\n'; else printf 'PWNED:0\n'; fi
if [ -f ./reached ]; then printf 'REACHED:1\n'; else printf 'REACHED:0\n'; fi
BASH;
        $output = $this->runBash($bash, $script, [$mode, $plan, $package], $work);
        $commandLine = (string) file_get_contents($work.'/cmdline.txt');
        preg_match('/^EXIT:(\d+)/m', $output, $exitMatch);
        preg_match('/^PWNED:(\d+)/m', $output, $pwnedMatch);
        preg_match('/^REACHED:(\d+)/m', $output, $reachedMatch);

        return [
            'exit' => isset($exitMatch[1]) ? (int) $exitMatch[1] : 99,
            'stdout' => $output,
            'pwned' => $pwnedMatch[1] ?? 'missing',
            'reached' => $reachedMatch[1] ?? 'missing',
            'commandLine' => $commandLine,
        ];
    }

    /**
     * @return array{exit: int, stdout: string, importer: string}
     */
    private function runValidation(string $mode, string $plan, string $package): array
    {
        $bash = $this->bashBinary();
        self::assertNotNull($bash);
        $remote = (string) file_get_contents($this->root().'/deploy/vds/gha-question-package-import-remote.sh');
        $cutoff = strpos($remote, 'cd "$APP_ROOT"');
        self::assertNotFalse($cutoff);
        $work = $this->workDir();
        file_put_contents($work.'/validate.sh', str_replace("\r\n", "\n", substr($remote, 0, $cutoff)));
        $script = <<<'BASH'
set +e
export MODE="$1"
export PLAN="$2"
export PACKAGE="$3"
export TESTLIG_CONTENT_ACTOR_EMAIL="$4"
bash ./validate.sh
status=$?
printf 'EXIT:%s\n' "$status"
if [ -f ./importer ]; then printf 'IMPORTER:1\n'; else printf 'IMPORTER:0\n'; fi
BASH;
        $output = $this->runBash($bash, $script, [$mode, $plan, $package, $this->fakeSecret()], $work);
        preg_match('/^EXIT:(\d+)/m', $output, $exitMatch);
        preg_match('/^IMPORTER:(\d+)/m', $output, $importerMatch);

        return [
            'exit' => isset($exitMatch[1]) ? (int) $exitMatch[1] : 99,
            'stdout' => $output,
            'importer' => $importerMatch[1] ?? 'missing',
        ];
    }

    private function probeScript(): string
    {
        return <<<'EOS'
set -Eeuo pipefail
printf 'probe_ok\n'
printf 'reached\n' > "__REACHED__"
EOS;
    }

    private function fakeSecret(): string
    {
        return '$(touch tl-secret-executed); echo injected; `id`; \';|&$(){}';
    }

    private function package(): string
    {
        return 'data/content/tymm-2026/grade-1/matematik/mat-1-3-3';
    }

    /**
     * @param list<string> $args
     */
    private function runBash(string $bash, string $script, array $args, ?string $cwd = null): string
    {
        $command = array_merge([$bash, '-s', '--'], $args);
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        self::assertIsResource($process);
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $stdout = \is_resource($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
        $stderr = \is_resource($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
        foreach ([1, 2] as $index) {
            if (\is_resource($pipes[$index])) {
                fclose($pipes[$index]);
            }
        }
        proc_close($process);

        return $stdout.$stderr;
    }

    private function workDir(): string
    {
        $dir = sys_get_temp_dir().'/tl-qpkg-stdin-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700));

        return $dir;
    }

    private function bashBinary(): ?string
    {
        $candidates = ['bash', 'C:\\Program Files\\Git\\bin\\bash.exe'];
        foreach ($candidates as $candidate) {
            if ('bash' !== $candidate && !is_file($candidate)) {
                continue;
            }
            $process = @proc_open([$candidate, '-c', 'echo ok'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!\is_resource($process)) {
                continue;
            }
            $stdout = \is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            if (\is_string($stdout) && str_contains($stdout, 'ok')) {
                return $candidate;
            }
        }

        return null;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}

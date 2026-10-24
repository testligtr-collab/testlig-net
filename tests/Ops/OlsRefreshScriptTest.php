<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;

final class OlsRefreshScriptTest extends TestCase
{
    private const SHA = 'c0ffee1234567890abcdef1234567890abcdef12';

    public function testWorkflowStaysClosedWithoutConfirmationOrWhenPaused(): void
    {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 2).'/.github/workflows/ops-ols-refresh.yml');

        self::assertStringContainsString('default: ""', $workflow);
        self::assertStringContainsString("github.event.inputs.confirm == 'refresh-current-ols'", $workflow);
        self::assertStringContainsString("github.event.inputs.confirm != 'refresh-current-ols'", $workflow);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED == 'true'", $workflow);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED != 'true'", $workflow);
        self::assertStringContainsString('OpenLiteSpeed refresh not confirmed', $workflow);
        self::assertStringContainsString('OpenLiteSpeed refresh skipped because SSH paused', $workflow);
        self::assertStringContainsString('VDS SSH operations are paused', $workflow);
        self::assertStringContainsString('group: testlig-vds-ssh', $workflow);
        self::assertStringContainsString('cancel-in-progress: false', $workflow);
        self::assertSame(1, preg_match_all('/^\s*ssh\s+/m', $workflow));
        self::assertSame(0, preg_match_all('/^\s*scp\s+/m', $workflow));
        self::assertStringContainsString('"${GITHUB_SHA}"', $workflow);
        self::assertStringNotContainsString('bfb32189f73f9ae849885e2d80de31e3a5deebf6', $workflow);
        self::assertStringNotContainsString('TESTLIG_OLS_REFRESH_ROOT', $workflow);
        self::assertStringNotContainsString('.env', $workflow);
        $script = (string) file_get_contents(\dirname(__DIR__, 2).'/deploy/vds/gha-ols-refresh-remote.sh');
        self::assertSame(1, substr_count($script, '"$lswsctrl" stop'));
        self::assertSame(1, substr_count($script, '"$lswsctrl" start'));
        self::assertStringNotContainsString('restart', $script);
        self::assertStringNotContainsString('cache:clear', $script);
        self::assertStringNotContainsString('cache:warmup', $script);
        self::assertStringNotContainsString('composer', $script);
        self::assertStringNotContainsString('doctrine:migrations', $script);
        self::assertStringNotContainsString('ln -s', $script);
        self::assertStringNotContainsString('.env', $script);
    }

    public function testBrokenSymlinkDoesNotRefresh(): void
    {
        $root = $this->fixture();
        $this->write($root.'/home/testlig.net/app/REVISION', self::SHA."\n");
        $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', self::SHA."\n");

        $result = $this->runRefresh($root, self::SHA);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('stop_reason=not_symlink', $result['output']);
        self::assertStringContainsString('lswsctrl_called=no', $result['output']);
        self::assertFileDoesNotExist($root.'/lsws.log');
    }

    public function testOutsideTargetDoesNotRefresh(): void
    {
        $root = $this->fixture();
        $this->write($root.'/outside/REVISION', self::SHA."\n");
        $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', self::SHA."\n");
        $this->link($root, $root.'/outside', 'home/testlig.net/app');

        $result = $this->runRefresh($root, self::SHA);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('stop_reason=target_outside_releases', $result['output']);
        self::assertStringContainsString('lswsctrl_called=no', $result['output']);
        self::assertFileDoesNotExist($root.'/lsws.log');
    }

    public function testMissingOrConflictingRevisionDoesNotRefresh(): void
    {
        $missing = $this->fixture();
        $this->release($missing, self::SHA, false);
        $missingResult = $this->runRefresh($missing, self::SHA);
        self::assertNotSame(0, $missingResult['exit']);
        self::assertStringContainsString('stop_reason=revision_unreadable', $missingResult['output']);
        self::assertFileDoesNotExist($missing.'/lsws.log');

        $conflict = $this->fixture();
        $this->release($conflict, self::SHA);
        $this->write($conflict.'/home/testlig.net/shared/CURRENT_REVISION', "1111111111111111111111111111111111111111\n");
        $conflictResult = $this->runRefresh($conflict, self::SHA);
        self::assertNotSame(0, $conflictResult['exit']);
        self::assertStringContainsString('stop_reason=revision_mismatch', $conflictResult['output']);
        self::assertFileDoesNotExist($conflict.'/lsws.log');
    }

    public function testStopFailureStillStartsOnce(): void
    {
        $root = $this->prepared();

        $result = $this->runRefresh($root, self::SHA, ['TESTLIG_OLS_REFRESH_STOP_STATUS' => '3']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('lswsctrl_stop_status=3', $result['output']);
        self::assertStringContainsString('lswsctrl_start_status=0', $result['output']);
        self::assertStringContainsString('release_identity_unchanged=yes', $result['output']);
        self::assertStringContainsString('result=refreshed', $result['output']);
        self::assertSame("stop\nstart\n", (string) file_get_contents($root.'/lsws.log'));
    }

    public function testStartOrHealthFailureIsNotSuccess(): void
    {
        $start = $this->prepared();
        $startResult = $this->runRefresh($start, self::SHA, ['TESTLIG_OLS_REFRESH_START_STATUS' => '4']);
        self::assertNotSame(0, $startResult['exit']);
        self::assertStringContainsString('lswsctrl_start_status=4', $startResult['output']);
        self::assertStringContainsString('stop_reason=start_failed', $startResult['output']);
        self::assertStringNotContainsString('result=refreshed', $startResult['output']);
        self::assertSame("stop\nstart\n", (string) file_get_contents($start.'/lsws.log'));

        $health = $this->prepared();
        $healthResult = $this->runRefresh($health, self::SHA, ['TESTLIG_OLS_REFRESH_CURL_CODE' => '500']);
        self::assertNotSame(0, $healthResult['exit']);
        self::assertStringContainsString('local_health_status=500', $healthResult['output']);
        self::assertStringContainsString('stop_reason=health_status', $healthResult['output']);
        self::assertSame("stop\nstart\n", (string) file_get_contents($health.'/lsws.log'));

        $home = $this->prepared();
        $homeResult = $this->runRefresh($home, self::SHA, ['TESTLIG_OLS_REFRESH_HOME_CODE' => '500']);
        self::assertNotSame(0, $homeResult['exit']);
        self::assertStringContainsString('local_home_status=500', $homeResult['output']);
        self::assertStringContainsString('stop_reason=home_status', $homeResult['output']);
        self::assertSame("stop\nstart\n", (string) file_get_contents($home.'/lsws.log'));
    }

    public function testRefreshKeepsTheSameReleaseIdentity(): void
    {
        $root = $this->prepared();

        $result = $this->runRefresh($root, self::SHA);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('release_before=rel', $result['output']);
        self::assertStringContainsString('release_after=rel', $result['output']);
        self::assertStringContainsString('revision_before='.self::SHA, $result['output']);
        self::assertStringContainsString('revision_after='.self::SHA, $result['output']);
        self::assertStringContainsString('release_identity_unchanged=yes', $result['output']);
        self::assertSame(self::SHA, trim((string) file_get_contents($root.'/home/testlig.net/releases/rel/REVISION')));
        self::assertSame("stop\nstart\n", (string) file_get_contents($root.'/lsws.log'));
    }

    public function testChangedRevisionAfterStartIsNotSuccess(): void
    {
        $root = $this->prepared();

        $result = $this->runRefresh($root, self::SHA, ['TESTLIG_OLS_REFRESH_MUTATE_REVISION' => 'yes']);

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('release_identity_unchanged=no', $result['output']);
        self::assertStringNotContainsString('result=refreshed', $result['output']);
        self::assertSame("stop\nstart\n", (string) file_get_contents($root.'/lsws.log'));
    }

    private function prepared(): string
    {
        $root = $this->fixture();
        $this->release($root, self::SHA);

        return $root;
    }

    private function release(string $root, string $sha, bool $revision = true): void
    {
        if ($revision) {
            $this->write($root.'/home/testlig.net/releases/rel/REVISION', $sha."\n");
        } else {
            $this->write($root.'/home/testlig.net/releases/rel/.keep', '');
        }
        $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', $sha."\n");
        $this->link($root, 'releases/rel', 'home/testlig.net/app');
        $this->binaries($root);
    }

    private function binaries(string $root): void
    {
        $this->write($root.'/bin/lswsctrl', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$1" >> "$TESTLIG_OLS_REFRESH_LSWS_LOG"
if [ "$1" = stop ] && [ "${TESTLIG_OLS_REFRESH_STOP_STATUS:-0}" != 0 ]; then
  printf '%s\n' stop-failed
  exit "$TESTLIG_OLS_REFRESH_STOP_STATUS"
fi
if [ "$1" = start ] && [ "${TESTLIG_OLS_REFRESH_START_STATUS:-0}" != 0 ]; then
  printf '%s\n' start-failed
  exit "$TESTLIG_OLS_REFRESH_START_STATUS"
fi
if [ "$1" = start ] && [ "${TESTLIG_OLS_REFRESH_MUTATE_REVISION:-}" = yes ]; then
  printf '%s\n' mutated > "$TESTLIG_OLS_REFRESH_REVISION_FILE"
fi
printf '%s\n' ok
exit 0
BASH);
        $this->write($root.'/bin/curl', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$TESTLIG_OLS_REFRESH_CURL_LOG"
seen=0
if [ -f "$TESTLIG_OLS_REFRESH_CURL_COUNT" ]; then
  seen="$(tr -d '\r\n' < "$TESTLIG_OLS_REFRESH_CURL_COUNT")"
fi
printf '%s\n' "$((seen + 1))" > "$TESTLIG_OLS_REFRESH_CURL_COUNT"
code="${TESTLIG_OLS_REFRESH_CURL_CODE:-200}"
if [ "$seen" != 0 ] && [ -n "${TESTLIG_OLS_REFRESH_HOME_CODE:-}" ]; then
  code="$TESTLIG_OLS_REFRESH_HOME_CODE"
fi
body="${TESTLIG_OLS_REFRESH_CURL_BODY:-{\"status\":\"ok\"}}"
out=""
prev=""
for arg in "$@"; do
  if [ "$prev" = "-o" ]; then
    out="$arg"
  fi
  prev="$arg"
done
if [ -n "$out" ] && [ "$out" != /dev/null ]; then
  printf '%s' "$body" > "$out"
fi
printf '%s' "$code"
exit 0
BASH);
        $this->bash('chmod +x '.$this->quote($root.'/bin/lswsctrl').' '.$this->quote($root.'/bin/curl'));
    }

    private function link(string $root, string $relativeTarget, string $link): void
    {
        $site = $this->bashPath($root);
        $this->bash('ln -sfn '.$this->quote($relativeTarget).' '.$this->quote($site.'/'.$link).'; test -L '.$this->quote($site.'/'.$link));
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array{exit: int, output: string}
     */
    private function runRefresh(string $root, string $sha, array $extra = []): array
    {
        $bash = $this->bashBinary();
        $script = $this->bashPath(\dirname(__DIR__, 2).'/deploy/vds/gha-ols-refresh-remote.sh');
        $env = getenv();
        $bin = $this->bashPath($root.'/bin');
        $path = $bin.':/usr/bin:/bin';
        $env['TESTLIG_OLS_REFRESH_ROOT'] = $this->bashPath($root);
        $env['TESTLIG_OLS_REFRESH_LSWSCTRL'] = $bin.'/lswsctrl';
        $env['TESTLIG_OLS_REFRESH_HEALTH_BODY'] = $this->bashPath($root.'/health.json');
        $env['TESTLIG_OLS_REFRESH_SLEEP'] = '0';
        $env['TESTLIG_OLS_REFRESH_LSWS_LOG'] = $this->bashPath($root.'/lsws.log');
        $env['TESTLIG_OLS_REFRESH_CURL_LOG'] = $this->bashPath($root.'/curl.log');
        $env['TESTLIG_OLS_REFRESH_CURL_COUNT'] = $this->bashPath($root.'/curl.count');
        $env['TESTLIG_OLS_REFRESH_REVISION_FILE'] = $this->bashPath($root.'/home/testlig.net/releases/rel/REVISION');
        $env['EXPECTED_SHA'] = $sha;
        $env['PATH'] = $path;
        $env['Path'] = $path;
        $env['MSYS'] = 'winsymlinks:lnk';
        unset($env['TESTLIG_OLS_REFRESH_STOP_STATUS'], $env['TESTLIG_OLS_REFRESH_START_STATUS'], $env['TESTLIG_OLS_REFRESH_CURL_CODE'], $env['TESTLIG_OLS_REFRESH_CURL_BODY'], $env['TESTLIG_OLS_REFRESH_HOME_CODE'], $env['TESTLIG_OLS_REFRESH_MUTATE_REVISION']);
        foreach ($extra as $name => $value) {
            $env[$name] = $value;
        }
        $pipes = [];
        $process = proc_open([$bash, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $stdout = \is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        $stderr = \is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return [
            'exit' => proc_close($process),
            'output' => (string) $stdout.(string) $stderr,
        ];
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir().'/testlig-ols-refresh-'.bin2hex(random_bytes(4));
        mkdir($root, 0700, true);

        return $root;
    }

    private function write(string $path, string $contents): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, $contents);
    }

    private function bash(string $command): void
    {
        $pipes = [];
        $env = getenv();
        $env['MSYS'] = 'winsymlinks:lnk';
        $process = proc_open([$this->bashBinary(), '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $stdout = \is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        $stderr = \is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        self::assertSame(0, proc_close($process), (string) $stdout.(string) $stderr);
    }

    private function bashBinary(): string
    {
        $binary = 'C:\\Program Files\\Git\\bin\\bash.exe';
        if (is_file($binary)) {
            return $binary;
        }

        return 'bash';
    }

    private function quote(string $path): string
    {
        return "'".str_replace("'", "'\\''", $this->bashPath($path))."'";
    }

    private function bashPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (1 === preg_match('/^([A-Za-z]):\/(.*)$/', $normalized, $matches)) {
            return '/'.strtolower($matches[1]).'/'.$matches[2];
        }

        return $normalized;
    }
}

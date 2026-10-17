<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use App\Security\WebRuntimeIdentityGate;
use PHPUnit\Framework\TestCase;

final class WebRuntimeIdentityWindowScriptTest extends TestCase
{
    private const SHA = 'd8264fc6b365b753c194f6f296a43da71fedc45b';

    private const SECRET = 'super-secret-value';

    private const PREVIOUS = 'previous-enabled-token';

    public function testOpenChangesOnlyTheTwoKeysAndHidesFileContents(): void
    {
        $root = $this->fixture();
        $release = $this->prepare($root, 'OTHER_SECRET='.self::SECRET."\nWEB_RUNTIME_IDENTITY_ENABLED=".self::PREVIOUS."\n");

        $result = $this->runWindow($root, 'open', self::SHA, ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('result=opened', $result['output']);
        self::assertStringContainsString('expires_window_seconds=900', $result['output']);
        self::assertStringContainsString('record=kept', $result['output']);
        self::assertStringContainsString('http_sent=no', $result['output']);
        self::assertStringContainsString('process_env_read=no', $result['output']);
        self::assertStringNotContainsString(self::SECRET, $result['output']);
        self::assertStringNotContainsString(self::PREVIOUS, $result['output']);
        self::assertStringNotContainsString('1700000900', $result['output']);
        $shared = $this->read($root, 'home/testlig.net/shared/.env.local');
        $releaseEnv = $this->read($root, $release.'/.env.local');
        self::assertSame($shared, $releaseEnv);
        self::assertStringContainsString('OTHER_SECRET='.self::SECRET, $shared);
        self::assertStringContainsString('WEB_RUNTIME_IDENTITY_ENABLED=1', $shared);
        self::assertStringContainsString('WEB_RUNTIME_IDENTITY_EXPIRES_AT=1700000900', $shared);
        self::assertStringNotContainsString(self::PREVIOUS, $shared);
        self::assertFileExists($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
    }

    public function testConflictAndPhpDumpStopWithoutChanges(): void
    {
        $cases = [
            'higher' => function (string $root, string $release): void {
                $this->write($root.'/'.$release.'/.env.prod', "WEB_RUNTIME_IDENTITY_ENABLED=1\n");
            },
            'dump' => function (string $root, string $release): void {
                $this->write($root.'/'.$release.'/.env.local.php', "<?php return ['APP_SECRET' => 'hidden'];\n");
            },
            'duplicate' => function (string $root, string $release): void {
                $this->write($root.'/home/testlig.net/shared/.env.local', "WEB_RUNTIME_IDENTITY_ENABLED=0\nWEB_RUNTIME_IDENTITY_ENABLED=1\n");
                $this->write($root.'/'.$release.'/.env.local', 'OTHER_SECRET='.self::SECRET."\n");
            },
            'ambiguous' => function (string $root, string $release): void {
                unset($release);
                $this->write($root.'/home/testlig.net/shared/.env.local', "export WEB_RUNTIME_IDENTITY_EXPIRES_AT=1\n");
            },
        ];
        foreach ($cases as $name => $arrange) {
            $root = $this->fixture();
            $release = $this->prepare($root, 'OTHER_SECRET='.self::SECRET."\n");
            $arrange($root, $release);
            $beforeShared = $this->read($root, 'home/testlig.net/shared/.env.local');
            $beforeRelease = $this->read($root, $release.'/.env.local');

            $result = $this->runWindow($root, 'open', self::SHA, ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);

            self::assertNotSame(0, $result['exit'], $name."\n".$result['output']);
            self::assertStringContainsString('result=stopped', $result['output'], $name);
            self::assertStringContainsString('files_changed=no', $result['output'], $name);
            self::assertStringNotContainsString(self::SECRET, $result['output'], $name);
            self::assertStringNotContainsString('hidden', $result['output'], $name);
            self::assertSame($beforeShared, $this->read($root, 'home/testlig.net/shared/.env.local'), $name);
            self::assertSame($beforeRelease, $this->read($root, $release.'/.env.local'), $name);
            self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
        }
    }

    public function testPartialFailureRestoresTheSharedFileAndKeepsTheRecordUntilVerified(): void
    {
        $root = $this->fixture();
        $original = 'OTHER_SECRET='.self::SECRET."\n";
        $release = $this->prepare($root, $original);

        $result = $this->runWindow($root, 'open', self::SHA, [
            'TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000',
            'TESTLIG_IDENTITY_WINDOW_FAIL' => 'after_shared',
        ]);

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('rollback=verified', $result['output']);
        self::assertStringContainsString('record=removed', $result['output']);
        self::assertStringContainsString('files_changed=no', $result['output']);
        self::assertStringNotContainsString(self::SECRET, $result['output']);
        self::assertSame($original, $this->read($root, 'home/testlig.net/shared/.env.local'));
        self::assertSame($original, $this->read($root, $release.'/.env.local'));
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
    }

    public function testRestoreReturnsPreviousAbsenceWithoutClobberingLaterLines(): void
    {
        $root = $this->fixture();
        $release = $this->prepare($root, 'OTHER_SECRET='.self::SECRET."\nWEB_RUNTIME_IDENTITY_ENABLED=".self::PREVIOUS."\n");
        $opened = $this->runWindow($root, 'open', self::SHA, ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);
        self::assertSame(0, $opened['exit'], $opened['output']);
        $this->write(
            $root.'/home/testlig.net/shared/.env.local',
            str_replace(self::SECRET, 'later-unrelated-value', $this->read($root, 'home/testlig.net/shared/.env.local')),
        );
        $this->write(
            $root.'/'.$release.'/.env.local',
            str_replace(self::SECRET, 'later-unrelated-value', $this->read($root, $release.'/.env.local')),
        );

        $restored = $this->runWindow($root, 'restore', self::SHA);

        self::assertSame(0, $restored['exit'], $restored['output']);
        self::assertStringContainsString('result=restored', $restored['output']);
        self::assertStringContainsString('record=removed', $restored['output']);
        self::assertStringNotContainsString(self::SECRET, $restored['output']);
        self::assertStringNotContainsString(self::PREVIOUS, $restored['output']);
        self::assertStringNotContainsString('later-unrelated-value', $restored['output']);
        self::assertStringNotContainsString('1700000900', $restored['output']);
        $shared = $this->read($root, 'home/testlig.net/shared/.env.local');
        self::assertStringContainsString('OTHER_SECRET=later-unrelated-value', $shared);
        self::assertStringContainsString('WEB_RUNTIME_IDENTITY_ENABLED='.self::PREVIOUS, $shared);
        self::assertStringNotContainsString('WEB_RUNTIME_IDENTITY_EXPIRES_AT=', $shared);
        self::assertSame($shared, $this->read($root, $release.'/.env.local'));
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');

        $again = $this->runWindow($root, 'restore', self::SHA);
        self::assertNotSame(0, $again['exit'], $again['output']);
        self::assertStringContainsString('stop_reason=record_absent', $again['output']);
        self::assertStringContainsString('files_changed=no', $again['output']);
        self::assertSame($shared, $this->read($root, 'home/testlig.net/shared/.env.local'));
    }

    public function testReleaseMismatchStopsBeforeAnyEdit(): void
    {
        $root = $this->fixture();
        $original = 'OTHER_SECRET='.self::SECRET."\n";
        $release = $this->prepare($root, $original);

        $result = $this->runWindow($root, 'open', str_repeat('a', 40), ['TESTLIG_IDENTITY_WINDOW_NOW' => '1700000000']);

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('stop_reason=release_mismatch', $result['output']);
        self::assertStringContainsString('files_changed=no', $result['output']);
        self::assertSame($original, $this->read($root, 'home/testlig.net/shared/.env.local'));
        self::assertSame($original, $this->read($root, $release.'/.env.local'));
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/web-runtime-identity-window.record');
    }

    public function testWorkflowStaysManualAndDoesNotCallTheSite(): void
    {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 2).'/.github/workflows/ops-web-runtime-identity-window.yml');
        $script = (string) file_get_contents(\dirname(__DIR__, 2).'/deploy/vds/gha-web-runtime-identity-window-remote.sh');
        self::assertSame(900, WebRuntimeIdentityGate::MAX_WINDOW_SECONDS);
        self::assertStringContainsString('window_seconds=900', $script);
        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertStringContainsString('open-web-runtime-identity', $workflow);
        self::assertStringContainsString('restore-web-runtime-identity', $workflow);
        self::assertStringContainsString("vars.VDS_SSH_PAUSED == 'true'", $workflow);
        self::assertStringContainsString('cancel-in-progress: false', $workflow);
        self::assertStringContainsString('testlig-vds-ssh', $workflow);
        self::assertStringContainsString('gha-ssh-config.sh', $workflow);
        self::assertStringContainsString('gha-ssh-close-master.sh', $workflow);
        self::assertSame(1, preg_match_all('/^\s*ssh\s+/m', $workflow));
        self::assertStringNotContainsString('curl ', $workflow);
        self::assertStringNotContainsString('curl ', $script);
        self::assertStringNotContainsString('environ', $script);
        self::assertStringNotContainsString('cmdline', $script);
        self::assertStringNotContainsString('lswsctrl', $script);
        self::assertStringNotContainsString('setfacl', $script);
        self::assertStringNotContainsString('/yonetim/', $script);
        self::assertDoesNotMatchRegularExpression('/\n\s*push:/', $workflow);
    }

    private function prepare(string $root, string $env): string
    {
        $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', self::SHA."\n");
        $this->write($root.'/home/testlig.net/shared/.env.local', $env);
        $this->write($root.'/home/testlig.net/releases/rel/REVISION', self::SHA."\n");
        $this->write($root.'/home/testlig.net/releases/rel/.env.local', $env);
        $bash = $this->bashBinary();
        $site = $this->bashPath($root.'/home/testlig.net');
        $pipes = [];
        $process = proc_open([$bash, '-c', "ln -sfn releases/rel '$site/app'; if [ -L '$site/app' ]; then echo SYMLINK; else echo DIRECTORY; fi"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stdout.(string) $stderr);
        if (\is_string($stdout) && str_contains($stdout, 'SYMLINK')) {
            return 'home/testlig.net/releases/rel';
        }
        $this->write($root.'/home/testlig.net/app/REVISION', self::SHA."\n");
        $this->write($root.'/home/testlig.net/app/.env.local', $env);

        return 'home/testlig.net/app';
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array{exit: int, output: string}
     */
    private function runWindow(string $root, string $mode, string $sha, array $extra = []): array
    {
        $bash = $this->bashBinary();
        $script = $this->bashPath(\dirname(__DIR__, 2).'/deploy/vds/gha-web-runtime-identity-window-remote.sh');
        $env = getenv();
        self::assertIsArray($env);
        $env['TESTLIG_IDENTITY_WINDOW_ROOT'] = $this->bashPath($root);
        $env['IDENTITY_WINDOW_MODE'] = $mode;
        $env['EXPECTED_SHA'] = $sha;
        unset($env['TESTLIG_IDENTITY_WINDOW_NOW'], $env['TESTLIG_IDENTITY_WINDOW_FAIL']);
        foreach ($extra as $name => $value) {
            $env[$name] = $value;
        }
        $pipes = [];
        $process = proc_open([$bash, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'output' => (string) $stdout.(string) $stderr,
        ];
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir().'/testlig-identity-window-'.bin2hex(random_bytes(4));
        mkdir($root, 0700, true);

        return $root;
    }

    private function read(string $root, string $relative): string
    {
        $text = file_get_contents($root.'/'.$relative);
        self::assertIsString($text);

        return $text;
    }

    private function write(string $path, string $contents): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, $contents);
    }

    private function bashPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (1 === preg_match('/^([A-Za-z]):\/(.*)$/', $normalized, $matches)) {
            return '/'.strtolower($matches[1]).'/'.$matches[2];
        }

        return $normalized;
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
        self::fail('bash is required');
    }
}

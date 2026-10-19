<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;

final class ClamavAppConnectionOperationTest extends TestCase
{
    private const SHA = '31b0835ddf19c1442b555eba3e57a9fb967016ab';

    private const LATER = '7d941ed696b076ab48246eb24e7eeab9ae4d0e98';

    private const SECRET = 'super-secret-value';

    public function testApplyWritesOnlyTheAllowlistAndKeepsTheGroupRecord(): void
    {
        $root = $this->fixture();
        $socket = $this->prepare($root, 'OTHER_SECRET='.self::SECRET."\n");
        $group = $root.'/home/testlig.net/shared/clamav-socket-group.record';
        file_put_contents($group, "format=1\nrelease_sha=".self::LATER."\n");

        $result = $this->runScript($root, 'apply', self::SHA);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('result=opened', $result['output']);
        self::assertStringContainsString('web_acceptance=pending', $result['output']);
        self::assertStringContainsString('ols_recycle=not_run', $result['output']);
        self::assertStringContainsString('group_record=untouched', $result['output']);
        self::assertStringContainsString('worker_process_env=unknown', $result['output']);
        self::assertStringContainsString('gate_restores_env=no', $result['output']);
        self::assertStringNotContainsString(self::SECRET, $result['output']);
        self::assertStringNotContainsString('clamav-link.sock', $result['output']);
        self::assertStringNotContainsString('4242', $result['output']);
        $shared = $this->read($root, 'home/testlig.net/shared/.env.local');
        self::assertStringContainsString('OTHER_SECRET='.self::SECRET, $shared);
        self::assertStringContainsString('QUESTION_IMAGE_CLAMAV_LINK_ENABLED=1', $shared);
        self::assertStringContainsString('QUESTION_IMAGE_CLAMAV_LINK_GROUP_GID=4242', $shared);
        self::assertStringContainsString('QUESTION_IMAGE_CLAMAV_LINK_EXPIRES_AT=1700000900', $shared);
        self::assertStringContainsString($socket, $shared);
        self::assertSame($shared, $this->read($root, 'home/testlig.net/releases/rel/.env.local'));
        self::assertSame("format=1\nrelease_sha=".self::LATER."\n", (string) file_get_contents($group));
        self::assertFileExists($root.'/home/testlig.net/shared/clamav-app-connection.record');
    }

    public function testHigherPriorityAndMismatchDoNotWrite(): void
    {
        $root = $this->fixture();
        $this->prepare($root, 'OTHER_SECRET='.self::SECRET."\n");
        $this->write($root.'/home/testlig.net/releases/rel/.env.prod', "QUESTION_IMAGE_CLAMAV_SOCKET=/tmp/other\n");
        $before = $this->read($root, 'home/testlig.net/shared/.env.local');

        $result = $this->runScript($root, 'apply', self::SHA);

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('stop_reason=higher_priority', $result['output']);
        self::assertStringContainsString('result=stopped', $result['output']);
        self::assertStringNotContainsString(self::SECRET, $result['output']);
        self::assertSame($before, $this->read($root, 'home/testlig.net/shared/.env.local'));
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/clamav-app-connection.record');

        $second = $this->fixture();
        $this->prepare($second, 'OTHER_SECRET='.self::SECRET."\n");
        $this->write($second.'/home/testlig.net/shared/CURRENT_REVISION', self::LATER."\n");
        $result = $this->runScript($second, 'apply', self::SHA);
        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('stop_reason=release_mismatch', $result['output']);
        self::assertFileDoesNotExist($second.'/home/testlig.net/shared/clamav-app-connection.record');
    }

    public function testPartialWriteRollsBackAndKeepsTheRecordWhenDeleteFails(): void
    {
        $root = $this->fixture();
        $original = 'OTHER_SECRET='.self::SECRET."\n";
        $this->prepare($root, $original);

        $result = $this->runScript($root, 'apply', self::SHA, [
            'TESTLIG_CLAMAV_LINK_FAIL' => 'after_shared,record_delete',
        ]);

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('result=stopped', $result['output']);
        self::assertStringNotContainsString('result=opened', $result['output']);
        self::assertStringContainsString('record=kept', $result['output']);
        self::assertSame($original, $this->read($root, 'home/testlig.net/shared/.env.local'));
        self::assertSame($original, $this->read($root, 'home/testlig.net/releases/rel/.env.local'));
        self::assertFileExists($root.'/home/testlig.net/shared/clamav-app-connection.record');
    }

    public function testRestoreUsesTheHistoricalRecordOnALaterRelease(): void
    {
        $root = $this->fixture();
        $original = 'OTHER_SECRET='.self::SECRET."\n";
        $this->prepare($root, $original);
        $opened = $this->runScript($root, 'apply', self::SHA);
        self::assertSame(0, $opened['exit'], $opened['output']);
        $record = (string) file_get_contents($root.'/home/testlig.net/shared/clamav-app-connection.record');
        $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', self::LATER."\n");
        $this->write($root.'/home/testlig.net/releases/rel/REVISION', self::LATER."\n");

        $result = $this->runScript($root, 'restore', self::LATER);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('result=restored', $result['output']);
        self::assertStringContainsString('web_acceptance=no', $result['output']);
        self::assertStringContainsString('historical_release_sha='.self::SHA, $result['output']);
        self::assertStringContainsString('gate_restores_env=no', $result['output']);
        self::assertSame($original, $this->read($root, 'home/testlig.net/shared/.env.local'));
        self::assertSame($original, $this->read($root, 'home/testlig.net/releases/rel/.env.local'));
        self::assertFileDoesNotExist($root.'/home/testlig.net/shared/clamav-app-connection.record');
        self::assertStringContainsString('release_sha='.self::SHA, $record);
        self::assertStringNotContainsString('release_sha='.self::LATER, $record);
    }

    private function prepare(string $root, string $env): string
    {
        $this->write($root.'/home/testlig.net/shared/CURRENT_REVISION', self::SHA."\n");
        $this->write($root.'/home/testlig.net/shared/.env.local', $env);
        $this->write($root.'/home/testlig.net/releases/rel/REVISION', self::SHA."\n");
        $this->write($root.'/home/testlig.net/releases/rel/.env.local', $env);
        $this->write($root.'/getent-virusgroup', "virusgroup:x:4242:\n");
        $database = $root.'/clamav-link-db';
        mkdir($database, 0700, true);
        $socket = $root.'/clamav-link.sock';
        $server = stream_socket_server('unix://'.$socket, $errno, $errstr);
        if (!\is_resource($server)) {
            self::markTestSkipped('Unix socket is unavailable: '.$errstr);
        }
        $bashSocket = $this->bashPath($socket);
        $bashDatabase = $this->bashPath($database);
        $this->write($root.'/etc/clamd.d/scan.conf', "LocalSocket {$bashSocket}\nDatabaseDirectory {$bashDatabase}\n");
        $site = $this->bashPath($root.'/home/testlig.net');
        $this->bash('chmod 755 '.$this->quote($root.'/home/testlig.net/shared')."; ln -sfn releases/rel '$site/app'");
        $this->openSockets[] = $server;
        self::assertNotEmpty($this->openSockets);

        return $bashSocket;
    }

    /** @var list<resource> */
    private array $openSockets = [];

    /**
     * @param array<string, string> $extra
     *
     * @return array{exit: int, output: string}
     */
    private function runScript(string $root, string $mode, string $sha, array $extra = []): array
    {
        $script = $this->bashPath(\dirname(__DIR__, 2).'/deploy/vds/gha-clamav-app-connection-remote.sh');
        $env = getenv();
        $env['TESTLIG_CLAMAV_LINK_ROOT'] = $this->bashPath($root);
        $env['CLAMAV_LINK_MODE'] = $mode;
        $env['EXPECTED_SHA'] = $sha;
        $env['TESTLIG_CLAMAV_LINK_NOW'] = '1700000000';
        unset($env['TESTLIG_CLAMAV_LINK_FAIL'], $env['TESTLIG_CLAMAV_LINK_MODE_LEDGER']);
        foreach ($extra as $name => $value) {
            $env[$name] = $value;
        }
        $pipes = [];
        $process = proc_open(['C:\\Program Files\\Git\\bin\\bash.exe', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!\is_resource($process)) {
            $process = proc_open(['bash', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        }
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
        $root = sys_get_temp_dir().'/testlig-clamav-link-'.bin2hex(random_bytes(4));
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

    private function bash(string $command): void
    {
        $pipes = [];
        $process = proc_open(['C:\\Program Files\\Git\\bin\\bash.exe', '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            $process = proc_open(['bash', '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        }
        self::assertIsResource($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
    }

    private function bashPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (1 === preg_match('/^([A-Za-z]):\/(.*)$/', $normalized, $matches)) {
            return '/'.strtolower($matches[1]).'/'.$matches[2];
        }

        return $normalized;
    }

    private function quote(string $path): string
    {
        return "'".$this->bashPath($path)."'";
    }
}

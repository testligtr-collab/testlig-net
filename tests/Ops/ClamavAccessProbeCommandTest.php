<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 2).'/deploy/vds/gha-clamav-access-probe.php';

final class ClamavAccessProbeCommandTest extends TestCase
{
    public function testPingCloseStillLetsVersionUseAnotherConnection(): void
    {
        $output = $this->probe('split');

        self::assertSame("app_ping=pong\napp_version_daily=59\n", $output);
    }

    public function testPartialReplyEarlyEofAndTimeoutStayDistinct(): void
    {
        $partial = $this->probe('partial');
        self::assertSame("app_ping=partial\napp_version_daily=59\n", $partial);

        $eof = $this->probe('eof');
        self::assertSame("app_ping=eof\napp_version_daily=59\n", $eof);

        $timedOut = $this->probe('timeout', 0.2);
        self::assertMatchesRegularExpression('/^app_ping=timed_out\napp_version_daily=(timed_out|unavailable)\n$/', $timedOut);
        self::assertStringNotContainsString('PONG', $timedOut);
        self::assertStringNotContainsString('ClamAV ', $timedOut);
    }

    public function testPartialWriteIsNotRetried(): void
    {
        $errno = 0;
        $errstr = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server);
        $name = stream_socket_get_name($server, false);
        self::assertIsString($name);
        $client = stream_socket_client('tcp://'.$name, $errno, $errstr, 1);
        self::assertIsResource($client);
        $accepted = stream_socket_accept($server, 1);
        self::assertIsResource($accepted);
        stream_set_blocking($client, false);
        stream_socket_shutdown($client, \STREAM_SHUT_WR);

        $result = \ClamavAccessProbeClient::writeAll($client, "nPING\n", microtime(true) + 1);

        self::assertContains($result, ['partial', 'eof']);
        fclose($client);
        fclose($accepted);
        fclose($server);
    }

    public function testRequiredHelperIsNotExecutedAsTheCliEntry(): void
    {
        self::assertFalse(clamav_access_probe_is_cli_entry());
    }

    public function testWorkflowBase64StdinProbeRefusesBeforeConnectingWhenIdentityDiffers(): void
    {
        $result = $this->workflowProbe('split');

        self::assertSame("process_identity_match=no\n", $result['output']);
        self::assertStringNotContainsString('PONG', $result['output']);
        self::assertStringNotContainsString('app_ping=', $result['output']);
        self::assertLessThan(12.0, $result['elapsed']);
    }

    public function testRecordedIdentityMatchIgnoresGroupOrderAndRejectsDifferentSets(): void
    {
        self::assertTrue(clamav_access_probe_recorded_identity_matches([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [5002],
        ]));
        self::assertFalse(clamav_access_probe_recorded_identity_matches([
            'euid' => 5002,
            'egid' => 5003,
            'groups' => [5002],
        ]));
        self::assertFalse(clamav_access_probe_recorded_identity_matches([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [7, 5002],
        ]));
        self::assertFalse(clamav_access_probe_recorded_identity_matches([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [5002, 7],
        ]));
        self::assertFalse(clamav_access_probe_recorded_identity_matches([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [],
        ]));
        self::assertFalse(clamav_access_probe_recorded_identity_matches([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => ['5002'],
        ]));
        self::assertFalse(clamav_access_probe_recorded_identity_matches(null));
        self::assertSame("process_identity=unavailable\n", clamav_access_probe_format_identity(null));
    }

    public function testConnectingProcessDoesNotOpenASocketWhenIdentityDiffers(): void
    {
        $opened = false;
        ob_start();
        clamav_access_probe_emit_for_credentials(
            ['euid' => 5002, 'egid' => 5002, 'groups' => [5002, 5002]],
            static function () use (&$opened): void {
                $opened = true;
            },
        );
        $output = ob_get_clean();

        self::assertFalse($opened);
        self::assertSame("process_identity_match=no\n", $output);

        $opened = false;
        ob_start();
        clamav_access_probe_emit_for_credentials(
            ['euid' => 5002, 'egid' => 5002, 'groups' => [5002]],
            static function () use (&$opened): void {
                $opened = true;
            },
        );
        ob_end_clean();

        self::assertTrue($opened);
    }

    public function testPingAndVersionTimeoutStayBounded(): void
    {
        $started = hrtime(true);
        $output = $this->probe('timeout', 5.0, 8);
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        self::assertMatchesRegularExpression('/^app_ping=timed_out\napp_version_daily=(timed_out|unavailable)\n$/', $output);
        self::assertGreaterThan(4.0, $elapsed);
        self::assertLessThan(12.0, $elapsed);
        self::assertStringNotContainsString('PONG', $output);
        self::assertStringNotContainsString('ClamAV ', $output);
    }

    private function probe(string $mode, float $timeout = 2.0, ?int $holdSeconds = null): string
    {
        $server = \dirname(__DIR__, 2).'/tests/Ops/ClamavAccessProbeFakeServer.php';
        $command = [\PHP_BINARY, $server, $mode];
        if (null !== $holdSeconds) {
            $command[] = '';
            $command[] = (string) $holdSeconds;
        }
        $pipes = [];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $address = \is_resource($pipes[1]) ? fgets($pipes[1]) : false;
        self::assertIsString($address);
        $address = trim($address);
        $connect = static function (float $connectTimeout) use ($address): mixed {
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client('tcp://'.$address, $errno, $errstr, min(1.0, $connectTimeout));

            return \is_resource($socket) ? $socket : null;
        };
        $output = \ClamavAccessProbeClient::report($connect, $timeout);
        $stderr = \is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exit = proc_close($process);
        self::assertSame(0, $exit, $stderr);

        return $output;
    }

    /**
     * @return array{output: string, elapsed: float, serverExit: int}
     */
    private function workflowProbe(string $mode, ?int $holdSeconds = null): array
    {
        if (!\in_array('unix', stream_get_transports(), true)) {
            self::markTestSkipped('The unix socket transport is required for the stdin probe regression.');
        }
        $socket = '/tmp/testlig-probe-'.bin2hex(random_bytes(4)).'.sock';
        $serverCommand = [\PHP_BINARY, \dirname(__DIR__, 2).'/tests/Ops/ClamavAccessProbeFakeServer.php', $mode, $socket];
        if (null !== $holdSeconds) {
            $serverCommand[] = (string) $holdSeconds;
        }
        $serverPipes = [];
        $server = proc_open($serverCommand, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $serverPipes);
        self::assertIsResource($server);
        $ready = \is_resource($serverPipes[1]) ? fgets($serverPipes[1]) : false;
        self::assertSame("ready\n", $ready);
        $source = file_get_contents(\dirname(__DIR__, 2).'/deploy/vds/gha-clamav-access-probe.php');
        self::assertIsString($source);
        $environment = getenv();
        $environment['ACCESS_PROBE_PHP_B64'] = base64_encode($source);
        $environment['PROBE_PHP'] = \PHP_BINARY;
        $environment['PROBE_SOCKET'] = $socket;
        $clientPipes = [];
        $started = hrtime(true);
        $client = proc_open(
            ['bash', '-c', 'printf %s "$ACCESS_PROBE_PHP_B64" | base64 -d | "$PROBE_PHP" -- "$PROBE_SOCKET"'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $clientPipes,
            null,
            $environment,
        );
        self::assertIsResource($client);
        $stdout = $clientPipes[1] ?? null;
        $stderrPipe = $clientPipes[2] ?? null;
        self::assertIsResource($stdout);
        self::assertIsResource($stderrPipe);
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderrPipe, false);
        $output = '';
        $stderr = '';
        while (true) {
            $output .= stream_get_contents($stdout);
            $stderr .= stream_get_contents($stderrPipe);
            $status = proc_get_status($client);
            if (!$status['running']) {
                $output .= stream_get_contents($stdout);
                $stderr .= stream_get_contents($stderrPipe);
                break;
            }
            $elapsed = (hrtime(true) - $started) / 1_000_000_000;
            if ($elapsed > 12) {
                proc_terminate($client);
                proc_terminate($server);
                self::fail('stdin probe exceeded 12 seconds');
            }
            usleep(50000);
        }
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        fclose($stdout);
        fclose($stderrPipe);
        $clientExit = $status['exitcode'];
        proc_close($client);
        $serverDeadline = hrtime(true) + 2_000_000_000;
        do {
            $serverStatus = proc_get_status($server);
            if (!$serverStatus['running']) {
                break;
            }
            usleep(20000);
        } while (hrtime(true) < $serverDeadline);
        if ($serverStatus['running']) {
            proc_terminate($server);
            $serverStatus = proc_get_status($server);
        }
        $serverStderr = \is_resource($serverPipes[2]) ? stream_get_contents($serverPipes[2]) : '';
        foreach ($serverPipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($server);
        $serverExit = $serverStatus['exitcode'];
        if (file_exists($socket)) {
            unlink($socket);
        }
        self::assertSame(0, $clientExit, $stderr."\n".$serverStderr);

        return ['output' => $output, 'elapsed' => $elapsed, 'serverExit' => $serverExit];
    }
}

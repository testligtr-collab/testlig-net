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

    private function probe(string $mode, float $timeout = 2.0): string
    {
        $server = \dirname(__DIR__, 2).'/tests/Ops/ClamavAccessProbeFakeServer.php';
        $pipes = [];
        $process = proc_open(
            [\PHP_BINARY, $server, $mode],
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
}

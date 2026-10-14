<?php

declare(strict_types=1);

namespace App\Tests\Question\Image;

use App\Question\Image\QuestionImageInspector;
use App\Question\Image\Scan\ClamdInstreamClient;
use App\Question\Image\Scan\ClamdInstreamTransport;
use App\Question\Image\Scan\ClamdTransportException;
use App\Question\Image\Scan\QuestionImageScanVerdict;
use App\Question\Image\Scan\UnixClamdSocketPath;
use PHPUnit\Framework\TestCase;

final class ClamdInstreamClientTest extends TestCase
{
    public function testFramesAChunkAndAcceptsOnlyTheExactCleanReply(): void
    {
        $transport = new RecordingClamdTransport("stream: OK\n");
        $path = $this->file('abc');
        try {
            $verdict = (new ClamdInstreamClient($transport))->scan($path);
        } finally {
            unlink($path);
        }

        self::assertSame(QuestionImageScanVerdict::Clean, $verdict);
        self::assertSame("nINSTREAM\n".pack('N', 3).'abc'.pack('N', 0), $transport->sent);
        self::assertSame(ClamdInstreamClient::TIMEOUT_SECONDS, $transport->timeoutSeconds);
        self::assertSame(ClamdInstreamClient::MAX_RESPONSE_BYTES, $transport->maxResponseBytes);
    }

    public function testFoundErrorSizeLimitMalformedAndTimeoutStayDistinct(): void
    {
        $cases = [
            ["stream: Eicar-Test-Signature FOUND\n", QuestionImageScanVerdict::Infected],
            ["stream: Heuristics.Encrypted.Zip ERROR\n", QuestionImageScanVerdict::Error],
            ["INSTREAM size limit exceeded. ERROR\n", QuestionImageScanVerdict::SizeLimit],
            ['stream: OK', QuestionImageScanVerdict::Malformed],
            ["stream: OK\n\n", QuestionImageScanVerdict::Malformed],
            ["stream: OK\nextra", QuestionImageScanVerdict::Malformed],
            ['', QuestionImageScanVerdict::Malformed],
        ];
        foreach ($cases as [$reply, $expected]) {
            $transport = new RecordingClamdTransport($reply);
            $path = $this->file('abc');
            try {
                $verdict = (new ClamdInstreamClient($transport))->scan($path);
            } finally {
                unlink($path);
            }
            self::assertSame($expected, $verdict);
            self::assertNotSame(QuestionImageScanVerdict::Clean, $verdict);
        }
    }

    public function testTimeoutConnectionAndResponseLimitDoNotBecomeClean(): void
    {
        $path = $this->file('abc');
        try {
            self::assertSame(
                QuestionImageScanVerdict::TimedOut,
                (new ClamdInstreamClient(new ThrowingClamdTransport(ClamdTransportException::timedOut())))->scan($path),
            );
            self::assertSame(
                QuestionImageScanVerdict::ConnectionFailed,
                (new ClamdInstreamClient(new ThrowingClamdTransport(ClamdTransportException::connectionFailed())))->scan($path),
            );
            self::assertSame(
                QuestionImageScanVerdict::Malformed,
                (new ClamdInstreamClient(new ThrowingClamdTransport(ClamdTransportException::responseLimit())))->scan($path),
            );
        } finally {
            unlink($path);
        }
    }

    public function testSocketPathRejectsNetworkAddresses(): void
    {
        $rejected = ['', 'tcp://127.0.0.1:3310', '127.0.0.1:3310', 'clamd.sock', '/tmp/../clamd.ctl', 'unix:///tmp/clamd.ctl'];
        foreach ($rejected as $path) {
            try {
                UnixClamdSocketPath::assert($path);
                self::fail($path);
            } catch (ClamdTransportException $exception) {
                self::assertSame('clamd_connection', $exception->getMessage());
            }
        }
        UnixClamdSocketPath::assert('/var/run/clamav/clamd.ctl');
    }

    public function testOversizedFileIsNotSent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'clam');
        self::assertIsString($path);
        $handle = fopen($path, 'w');
        self::assertIsResource($handle);
        fwrite($handle, str_repeat('a', QuestionImageInspector::MAX_BYTES + 1));
        fclose($handle);
        $transport = new RecordingClamdTransport("stream: OK\n");
        try {
            $verdict = (new ClamdInstreamClient($transport))->scan($path);
        } finally {
            unlink($path);
        }
        self::assertSame(QuestionImageScanVerdict::SizeLimit, $verdict);
        self::assertSame('', $transport->sent);
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'clam');
        self::assertIsString($path);
        file_put_contents($path, $bytes);

        return $path;
    }
}

final class RecordingClamdTransport implements ClamdInstreamTransport
{
    public string $sent = '';
    public float $timeoutSeconds = 0;
    public int $maxResponseBytes = 0;

    public function __construct(
        private readonly string $response,
    ) {
    }

    public function exchange(iterable $framed, float $timeoutSeconds, int $maxResponseBytes): string
    {
        $this->timeoutSeconds = $timeoutSeconds;
        $this->maxResponseBytes = $maxResponseBytes;
        foreach ($framed as $piece) {
            $this->sent .= $piece;
        }

        return $this->response;
    }
}

final class ThrowingClamdTransport implements ClamdInstreamTransport
{
    public function __construct(
        private readonly ClamdTransportException $failure,
    ) {
    }

    public function exchange(iterable $framed, float $timeoutSeconds, int $maxResponseBytes): string
    {
        throw $this->failure;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Question\Image;

use App\Question\Image\QuestionImageInspector;
use App\Question\Image\Scan\ClamdInstreamClient;
use App\Question\Image\Scan\ClamdInstreamTransport;
use App\Question\Image\Scan\ClamdScanResult;
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
            $result = $this->scan($transport, $path, 'abc');
        } finally {
            unlink($path);
        }

        self::assertSame(QuestionImageScanVerdict::Clean, $result->verdict);
        self::assertTrue($result->streamMatched);
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
                $result = $this->scan($transport, $path, 'abc');
            } finally {
                unlink($path);
            }
            self::assertSame($expected, $result->verdict);
            self::assertTrue($result->streamMatched);
            self::assertNotSame(QuestionImageScanVerdict::Clean, $result->verdict);
        }
    }

    public function testTimeoutConnectionAndResponseLimitDoNotBecomeClean(): void
    {
        $path = $this->file('abc');
        try {
            self::assertSame(
                QuestionImageScanVerdict::TimedOut,
                $this->scan(new ThrowingClamdTransport(ClamdTransportException::timedOut()), $path, 'abc')->verdict,
            );
            self::assertSame(
                QuestionImageScanVerdict::ConnectionFailed,
                $this->scan(new ThrowingClamdTransport(ClamdTransportException::connectionFailed()), $path, 'abc')->verdict,
            );
            self::assertSame(
                QuestionImageScanVerdict::Malformed,
                $this->scan(new ThrowingClamdTransport(ClamdTransportException::responseLimit()), $path, 'abc')->verdict,
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

    public function testOversizedExpectationIsNotSent(): void
    {
        $path = $this->file('abc');
        $transport = new RecordingClamdTransport("stream: OK\n");
        try {
            $result = (new ClamdInstreamClient($transport))->scan($path, QuestionImageInspector::MAX_BYTES + 1, hash('sha256', 'abc'));
        } finally {
            unlink($path);
        }
        self::assertSame(QuestionImageScanVerdict::SizeLimit, $result->verdict);
        self::assertFalse($result->streamMatched);
        self::assertSame('', $transport->sent);
    }

    public function testBrokenReadsAndChangedBytesDoNotAcceptACleanReply(): void
    {
        stream_wrapper_register('testligscan', ScriptedScanStream::class);
        $transport = new RecordingClamdTransport("stream: OK\n");
        $client = new ClamdInstreamClient($transport);
        /** @var array<string, list<string|false>> $cases */
        $cases = [
            'read-error' => ['ab', false],
            'empty-before-eof' => ['ab', ''],
            'shorter' => ['ab'],
            'longer' => ['abcd', 'Z'],
            'different' => ['wxyz'],
        ];
        try {
            foreach ($cases as $reads) {
                ScriptedScanStream::$reads = $reads;
                ScriptedScanStream::$closed = false;
                $transport->sent = '';
                $result = $client->scan('testligscan://image', 4, hash('sha256', 'abcd'));
                self::assertNotSame(QuestionImageScanVerdict::Clean, $result->verdict);
                self::assertFalse($result->streamMatched);
                self::assertFalse(str_ends_with($transport->sent, pack('N', 0)));
                self::assertTrue(ScriptedScanStream::$closed);
            }
        } finally {
            stream_wrapper_unregister('testligscan');
        }
    }

    private function scan(ClamdInstreamTransport $transport, string $path, string $bytes): ClamdScanResult
    {
        return (new ClamdInstreamClient($transport))->scan($path, \strlen($bytes), hash('sha256', $bytes));
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

final class ScriptedScanStream
{
    public mixed $context = null;

    /** @var list<string|false> */
    public static array $reads = [];

    public static bool $closed = false;

    private int $index = 0;

    private bool $eof = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($path, $mode, $options, $openedPath);
        $this->index = 0;
        $this->eof = false;
        self::$closed = false;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        unset($count);
        if ($this->index >= \count(self::$reads)) {
            $this->eof = true;

            return '';
        }
        $value = self::$reads[$this->index];
        ++$this->index;
        if (false === $value) {
            return false;
        }
        if ('' === $value) {
            return '';
        }

        return $value;
    }

    public function stream_eof(): bool
    {
        return $this->eof;
    }

    /**
     * @return array{mode: int, size: int}
     */
    public function stream_stat(): array
    {
        return ['mode' => 0100644, 'size' => 0];
    }

    public function stream_close(): void
    {
        self::$closed = true;
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

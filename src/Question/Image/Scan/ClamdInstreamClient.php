<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

use App\Question\Image\QuestionImageInspector;

/**
 * Real clamd INSTREAM client. Tests replace the transport; they do not replace this framing.
 *
 * The reply is accepted only after the bytes actually sent match the expected size and SHA-256.
 */
final class ClamdInstreamClient
{
    public const TIMEOUT_SECONDS = 30.0;
    public const MAX_RESPONSE_BYTES = 512;
    private const CHUNK_BYTES = 8192;

    public function __construct(
        private readonly ClamdInstreamTransport $transport,
    ) {
    }

    public function scan(string $path, int $expectedByteSize, string $expectedSha256): ClamdScanResult
    {
        if ($expectedByteSize < 1 || $expectedByteSize > QuestionImageInspector::MAX_BYTES) {
            return new ClamdScanResult(QuestionImageScanVerdict::SizeLimit, false);
        }
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $expectedSha256)) {
            return new ClamdScanResult(QuestionImageScanVerdict::HashMismatch, false);
        }
        $handle = fopen($path, 'rb+');
        if (!\is_resource($handle)) {
            return new ClamdScanResult(QuestionImageScanVerdict::Error, false);
        }
        $completed = false;
        try {
            $response = $this->transport->exchange(
                $this->frames($handle, $expectedByteSize, $expectedSha256, $completed),
                self::TIMEOUT_SECONDS,
                self::MAX_RESPONSE_BYTES,
            );
        } catch (QuestionImageStreamException $exception) {
            return new ClamdScanResult($this->streamVerdict($exception), false);
        } catch (ClamdTransportException $exception) {
            return new ClamdScanResult(match ($exception->getMessage()) {
                'clamd_timeout' => QuestionImageScanVerdict::TimedOut,
                'clamd_response_limit' => QuestionImageScanVerdict::Malformed,
                default => QuestionImageScanVerdict::ConnectionFailed,
            }, false);
        } catch (\Throwable) {
            return new ClamdScanResult(QuestionImageScanVerdict::Error, false);
        } finally {
            fclose($handle);
        }
        if (!$completed) {
            return new ClamdScanResult(QuestionImageScanVerdict::HashMismatch, false);
        }

        return new ClamdScanResult(ClamdInstreamReply::parse($response), true);
    }

    /**
     * @param resource $handle
     *
     * @return \Generator<int, string>
     */
    private function frames($handle, int $expectedByteSize, string $expectedSha256, bool &$completed): \Generator
    {
        $completed = false;
        $sent = 0;
        $hash = hash_init('sha256');
        yield "nINSTREAM\n";
        while ($sent < $expectedByteSize) {
            $remaining = $expectedByteSize - $sent;
            if ($remaining < 1) {
                break;
            }
            $length = $remaining > self::CHUNK_BYTES ? self::CHUNK_BYTES : $remaining;
            $chunk = fread($handle, $length);
            if (false === $chunk) {
                throw QuestionImageStreamException::read();
            }
            if ('' === $chunk) {
                throw feof($handle) ? QuestionImageStreamException::incomplete() : QuestionImageStreamException::emptyRead();
            }
            hash_update($hash, $chunk);
            $sent += \strlen($chunk);
            yield pack('N', \strlen($chunk)).$chunk;
        }
        $extra = fread($handle, 1);
        if (false === $extra) {
            throw QuestionImageStreamException::read();
        }
        if ('' !== $extra || !feof($handle)) {
            throw '' === $extra ? QuestionImageStreamException::emptyRead() : QuestionImageStreamException::size();
        }
        $digest = hash_final($hash);
        if ($sent !== $expectedByteSize || !hash_equals($expectedSha256, $digest)) {
            throw QuestionImageStreamException::mismatch();
        }
        $completed = true;
        yield pack('N', 0);
    }

    private function streamVerdict(QuestionImageStreamException $exception): QuestionImageScanVerdict
    {
        return match ($exception->getMessage()) {
            'question_image_read', 'question_image_empty_read' => QuestionImageScanVerdict::Error,
            'question_image_stream_size' => QuestionImageScanVerdict::SizeLimit,
            default => QuestionImageScanVerdict::HashMismatch,
        };
    }
}

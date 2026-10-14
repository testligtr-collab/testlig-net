<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

use App\Question\Image\QuestionImageInspector;

/**
 * Real clamd INSTREAM client. Tests replace the transport; they do not replace this framing.
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

    public function scan(string $path): QuestionImageScanVerdict
    {
        $size = filesize($path);
        if (!\is_int($size) || $size < 1 || $size > QuestionImageInspector::MAX_BYTES) {
            return QuestionImageScanVerdict::SizeLimit;
        }
        $handle = fopen($path, 'rb+');
        if (!\is_resource($handle)) {
            return QuestionImageScanVerdict::Error;
        }
        try {
            $response = $this->transport->exchange(
                $this->frames($handle),
                self::TIMEOUT_SECONDS,
                self::MAX_RESPONSE_BYTES,
            );
        } catch (ClamdTransportException $exception) {
            return match ($exception->getMessage()) {
                'clamd_timeout' => QuestionImageScanVerdict::TimedOut,
                'clamd_response_limit' => QuestionImageScanVerdict::Malformed,
                default => QuestionImageScanVerdict::ConnectionFailed,
            };
        } catch (\Throwable) {
            return QuestionImageScanVerdict::Error;
        } finally {
            fclose($handle);
        }

        return ClamdInstreamReply::parse($response);
    }

    /**
     * @param resource $handle
     *
     * @return \Generator<int, string>
     */
    private function frames($handle): \Generator
    {
        yield "nINSTREAM\n";
        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK_BYTES);
            if (!\is_string($chunk) || '' === $chunk) {
                break;
            }
            yield pack('N', \strlen($chunk)).$chunk;
        }
        yield pack('N', 0);
    }
}

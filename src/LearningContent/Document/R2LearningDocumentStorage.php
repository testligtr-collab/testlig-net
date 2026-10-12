<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Exception\LearningContentException;
use Psr\Log\LoggerInterface;

/**
 * Downloads a private R2 object once into a verified temporary file.
 */
final class R2LearningDocumentStorage implements LearningDocumentByteStore
{
    public const TEMP_MAX_AGE_SECONDS = LearningDocumentVerifiedCopy::TEMP_MAX_AGE_SECONDS;

    public const CLEANUP_FAILED_EVENT = 'learning_document.r2_cleanup_failed';

    private readonly LearningDocumentVerifiedCopy $copies;

    public function __construct(
        private readonly R2ObjectClient $client,
        string $tempDirectory,
        private readonly int $maxBytes = PdfDocumentInspector::MAX_BYTES,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->copies = new LearningDocumentVerifiedCopy($tempDirectory, $maxBytes);
    }

    public function write(string $bytes): string
    {
        if (\strlen($bytes) > $this->maxBytes) {
            throw LearningContentException::assetInvalid(PdfDocumentInspector::MESSAGE_SIZE);
        }
        $key = bin2hex(random_bytes(16));
        try {
            $this->client->putPrivate($key, $bytes);
        } catch (LearningContentException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
        }

        return $key;
    }

    public function read(string $storageKey, string $expectedSha256): string
    {
        return $this->copies->read($storageKey, $expectedSha256, $this->client->readPrivate($storageKey, $this->maxBytes));
    }

    public function absolutePath(string $storageKey): string
    {
        return $this->copies->absolutePath($storageKey);
    }

    public function deleteQuietly(string $storageKey): void
    {
        try {
            if (1 !== preg_match('/^[a-f0-9]{32}$/', $storageKey)) {
                throw LearningContentException::notFound();
            }
            $this->client->delete($storageKey);
        } catch (\Throwable) {
            $this->logger?->error(self::CLEANUP_FAILED_EVENT);
        }
        $this->copies->discard($storageKey);
    }

    public function releaseVerifiedCopies(): void
    {
        $this->copies->releaseVerifiedCopies();
    }

    public function sweepExpired(): void
    {
        $this->copies->sweepExpired();
    }
}

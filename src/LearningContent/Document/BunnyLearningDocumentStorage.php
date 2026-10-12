<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Exception\LearningContentException;
use Psr\Log\LoggerInterface;

/**
 * Downloads a private Bunny Storage PDF once into the shared verified temporary file.
 */
final class BunnyLearningDocumentStorage implements LearningDocumentByteStore
{
    public const CLEANUP_FAILED_EVENT = 'learning_document.bunny_cleanup_failed';

    private readonly LearningDocumentVerifiedCopy $copies;

    public function __construct(
        private readonly BunnyStorageClient $client,
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

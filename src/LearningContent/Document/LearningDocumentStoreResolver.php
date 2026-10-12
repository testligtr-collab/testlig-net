<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Entity\LearningDocumentAsset;
use App\Enum\LearningDocumentStorageProvider;
use App\Exception\LearningContentException;

/**
 * The upload setting chooses the provider of a new PDF only.
 * An existing asset keeps the provider stored on its row.
 */
final class LearningDocumentStoreResolver
{
    public function __construct(
        private readonly LearningDocumentStorage $local,
        private readonly R2LearningDocumentStorage $r2,
        private readonly BunnyLearningDocumentStorage $bunny,
        private readonly R2Configuration $r2Configuration,
        private readonly BunnyStorageConfiguration $bunnyConfiguration,
        private readonly string $uploadProvider,
    ) {
    }

    public function forUpload(): LearningDocumentUploadTarget
    {
        $provider = strtolower(trim($this->uploadProvider));
        if ('local' === $provider || '' === $provider) {
            return new LearningDocumentUploadTarget($this->local, LearningDocumentStorageProvider::Local);
        }
        if ('bunny' === $provider) {
            $this->bunnyConfiguration->assertReady();

            return new LearningDocumentUploadTarget($this->bunny, LearningDocumentStorageProvider::Bunny);
        }
        if ('r2' !== $provider) {
            throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
        }
        $this->r2Configuration->assertReady();

        return new LearningDocumentUploadTarget($this->r2, LearningDocumentStorageProvider::R2);
    }

    public function forAsset(LearningDocumentAsset $asset): LearningDocumentByteStore
    {
        return match ($asset->getStorageProvider()) {
            LearningDocumentStorageProvider::Local => $this->local,
            LearningDocumentStorageProvider::R2 => $this->r2,
            LearningDocumentStorageProvider::Bunny => $this->bunny,
        };
    }
}

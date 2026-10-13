<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Entity\Institution;
use App\Entity\StoredMediaAsset;
use App\Entity\User;
use App\Enum\QuestionScope;
use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Exception\QuestionException;
use App\LearningContent\Media\StorageKeyFactory;
use Symfony\Component\Uid\UuidV7;

/**
 * Writes one private question image and registers pending metadata.
 * A failed registration deletes that object once and keeps the original error.
 */
final class QuestionImageRegistrar
{
    public function __construct(
        private readonly QuestionImageInspector $inspector,
        private readonly QuestionImageStoreResolver $stores,
        private readonly StorageKeyFactory $storageKeys,
        private readonly QuestionImageMetadataPort $metadata,
        private readonly QuestionImageAuthorizer $authorizer,
    ) {
    }

    public function receive(User $actor, QuestionScope $scope, ?Institution $institution, string $bytes, ?string $declaredMime = null): StoredMediaAsset
    {
        $this->authorizer->assertMayUpload($actor, $scope, $institution);
        $inspection = $this->inspector->inspect($bytes, $declaredMime);
        $assetId = new UuidV7();
        $storageScope = QuestionScope::Platform === $scope ? StoredMediaAssetScope::Platform : StoredMediaAssetScope::Institution;
        $storageKey = $this->storageKeys->create(
            $storageScope,
            $institution?->getId(),
            StoredMediaAssetKind::Image,
            $assetId,
            $inspection['sha256'],
        );
        $provider = $this->stores->provider();
        $this->stores->store()->write($storageKey, $bytes, $inspection['mime'], $inspection['sha256']);
        try {
            return $this->metadata->register(
                $actor,
                $storageScope,
                QuestionScope::Platform === $scope ? null : $institution,
                $provider,
                $this->displayName($inspection['mime']),
                $inspection['mime'],
                \strlen($bytes),
                $inspection['sha256'],
                $assetId,
            );
        } catch (\Throwable $error) {
            $this->stores->storeFor($provider)->deleteQuietly($storageKey);
            throw $error;
        }
    }

    public function deleteQuietly(StoredMediaAsset $asset): void
    {
        $this->stores->storeFor($asset->getStorageProvider())->deleteQuietly($asset->getStorageKey());
    }

    private function displayName(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'soru.jpg',
            'image/png' => 'soru.png',
            'image/webp' => 'soru.webp',
            default => throw QuestionException::imageRejected('question_image_type'),
        };
    }
}

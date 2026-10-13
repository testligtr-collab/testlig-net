<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Entity\Institution;
use App\Entity\User;
use App\Enum\QuestionScope;
use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\LearningContent\Media\StorageKeyFactory;
use Symfony\Component\Uid\UuidV7;

/**
 * Writes one private question image. Metadata commits only with the question transaction.
 * A failed save deletes that object once and keeps the original error.
 */
final class QuestionImageRegistrar
{
    /** @var array<string, true> */
    private array $discarded = [];

    public function __construct(
        private readonly QuestionImageInspector $inspector,
        private readonly QuestionImageStoreResolver $stores,
        private readonly StorageKeyFactory $storageKeys,
        private readonly QuestionImageAuthorizer $authorizer,
    ) {
    }

    public function stage(User $actor, QuestionScope $scope, ?Institution $institution, string $bytes, ?string $declaredMime = null): StagedQuestionImage
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

        return new StagedQuestionImage(
            $assetId,
            $storageKey,
            $provider,
            $inspection['mime'],
            \strlen($bytes),
            $inspection['sha256'],
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $save
     *
     * @return T
     */
    public function finish(?StagedQuestionImage $staged, callable $save): mixed
    {
        try {
            return $save();
        } catch (\Throwable $error) {
            $this->discard($staged);
            throw $error;
        }
    }

    public function discard(?StagedQuestionImage $staged): void
    {
        if (!$staged instanceof StagedQuestionImage || isset($this->discarded[$staged->storageKey])) {
            return;
        }
        $this->discarded[$staged->storageKey] = true;
        $this->stores->storeFor($staged->provider)->deleteQuietly($staged->storageKey);
    }
}

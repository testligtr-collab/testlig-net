<?php

declare(strict_types=1);

namespace App\LearningContent\Media;

use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Exception\LearningContentException;
use Symfony\Component\Uid\Uuid;

/**
 * App-generated storage keys only. Rejects path traversal and absolute paths.
 * Never returns or accepts absolute filesystem paths from callers.
 */
final class StorageKeyFactory
{
    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9_\/.-]{0,500}$/';

    public function create(
        StoredMediaAssetScope $scope,
        ?Uuid $institutionId,
        StoredMediaAssetKind $kind,
        Uuid $assetId,
        string $contentSha256,
    ): string {
        if (StoredMediaAssetScope::Platform === $scope && null !== $institutionId) {
            throw LearningContentException::scopeMismatch();
        }
        if (StoredMediaAssetScope::Institution === $scope && null === $institutionId) {
            throw LearningContentException::scopeMismatch();
        }
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $contentSha256)) {
            throw LearningContentException::assetInvalid('contentSha256 must be 64 lowercase hex characters.');
        }

        $tenantSegment = StoredMediaAssetScope::Platform === $scope
            ? 'platform'
            : 'institution/'.$institutionId->toRfc4122();

        $key = \sprintf(
            'media/%s/%s/%s/%s',
            $tenantSegment,
            $kind->value,
            $assetId->toRfc4122(),
            substr($contentSha256, 0, 16),
        );

        $this->assertSafeKey($key);

        return $key;
    }

    public function assertSafeKey(string $storageKey): void
    {
        if ('' === $storageKey || \strlen($storageKey) > 512) {
            throw LearningContentException::assetInvalid('storageKey length is invalid.');
        }
        if (
            str_contains($storageKey, "\0")
            || str_contains($storageKey, '\\')
            || str_contains($storageKey, '..')
            || str_starts_with($storageKey, '/')
            || 1 === preg_match('#^[a-zA-Z]:#', $storageKey)
        ) {
            throw LearningContentException::assetInvalid('storageKey must not contain traversal, null bytes, or absolute paths.');
        }
        if (1 !== preg_match(self::KEY_PATTERN, $storageKey)) {
            throw LearningContentException::assetInvalid('storageKey format is invalid.');
        }
    }
}

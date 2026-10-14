<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

use App\Entity\StoredMediaAsset;
use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\StoredMediaScanStatus;
use App\Enum\StoredMediaStorageProvider;
use App\Question\Image\QuestionImageInspector;
use Symfony\Component\Uid\Uuid;

final class QuestionImageScanSnapshot
{
    public function __construct(
        public readonly Uuid $assetId,
        public readonly StoredMediaAssetScope $scope,
        public readonly ?Uuid $institutionId,
        public readonly StoredMediaAssetKind $kind,
        public readonly StoredMediaAssetStatus $status,
        public readonly StoredMediaScanStatus $scanStatus,
        public readonly StoredMediaStorageProvider $provider,
        public readonly string $storageKey,
        public readonly int $byteSize,
        public readonly string $sha256,
    ) {
    }

    public static function from(StoredMediaAsset $asset): self
    {
        return new self(
            $asset->getId(),
            $asset->getScope(),
            $asset->getInstitution()?->getId(),
            $asset->getKind(),
            $asset->getStatus(),
            $asset->getScanStatus(),
            $asset->getStorageProvider(),
            $asset->getStorageKey(),
            $asset->getByteSize(),
            $asset->getContentSha256(),
        );
    }

    public function isPendingImage(): bool
    {
        return StoredMediaAssetKind::Image === $this->kind
            && StoredMediaAssetStatus::Pending === $this->status
            && StoredMediaScanStatus::Pending === $this->scanStatus
            && $this->byteSize >= 1
            && $this->byteSize <= QuestionImageInspector::MAX_BYTES
            && 1 === preg_match('/^[0-9a-f]{64}$/', $this->sha256)
            && \in_array($this->provider, [StoredMediaStorageProvider::Local, StoredMediaStorageProvider::Bunny], true);
    }

    public function matches(StoredMediaAsset $asset): bool
    {
        $institutionId = $asset->getInstitution()?->getId();
        $sameInstitution = (null === $this->institutionId && null === $institutionId)
            || ($this->institutionId instanceof Uuid && $institutionId instanceof Uuid && $this->institutionId->equals($institutionId));

        return $this->assetId->equals($asset->getId())
            && $sameInstitution
            && $this->scope === $asset->getScope()
            && $this->kind === $asset->getKind()
            && $this->status === $asset->getStatus()
            && $this->scanStatus === $asset->getScanStatus()
            && $this->provider === $asset->getStorageProvider()
            && $this->byteSize === $asset->getByteSize()
            && hash_equals($this->storageKey, $asset->getStorageKey())
            && hash_equals($this->sha256, $asset->getContentSha256());
    }
}

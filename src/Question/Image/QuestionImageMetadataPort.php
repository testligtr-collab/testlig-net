<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Entity\Institution;
use App\Entity\StoredMediaAsset;
use App\Entity\User;
use App\Enum\StoredMediaAssetScope;
use App\Enum\StoredMediaStorageProvider;
use Symfony\Component\Uid\Uuid;

interface QuestionImageMetadataPort
{
    public function register(
        User $actor,
        StoredMediaAssetScope $scope,
        ?Institution $institution,
        StoredMediaStorageProvider $provider,
        string $filename,
        string $mimeType,
        int $byteSize,
        string $sha256,
        Uuid $assetId,
    ): StoredMediaAsset;
}

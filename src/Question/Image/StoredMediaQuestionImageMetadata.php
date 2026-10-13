<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Entity\Institution;
use App\Entity\StoredMediaAsset;
use App\Entity\User;
use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Enum\StoredMediaStorageProvider;
use App\Service\StoredMediaAssetManager;
use Symfony\Component\Uid\Uuid;

final class StoredMediaQuestionImageMetadata implements QuestionImageMetadataPort
{
    public function __construct(private readonly StoredMediaAssetManager $assets)
    {
    }

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
    ): StoredMediaAsset {
        return $this->assets->registerMetadata(
            $actor,
            $scope,
            $institution,
            StoredMediaAssetKind::Image,
            $provider,
            $filename,
            $mimeType,
            $byteSize,
            $sha256,
            'question_image_upload',
            $assetId,
        );
    }
}

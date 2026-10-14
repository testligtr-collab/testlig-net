<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Enum\StoredMediaAssetScope;
use App\Exception\QuestionException;
use Symfony\Component\Uid\Uuid;

/**
 * App-generated question image object keys. Not the PDF 32-hex contract.
 */
final class QuestionImageObjectKey
{
    public const PATTERN = '#^media/(platform|institution/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/image/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/[0-9a-f]{16}$#';

    public static function assert(string $storageKey): void
    {
        if (1 !== preg_match(self::PATTERN, $storageKey)) {
            throw QuestionException::imageRejected('question_image_store');
        }
    }

    public static function assertForAsset(string $storageKey, StoredMediaAssetScope $scope, ?Uuid $institutionId): void
    {
        self::assert($storageKey);
        if (StoredMediaAssetScope::Platform === $scope) {
            if (null !== $institutionId || !str_starts_with($storageKey, 'media/platform/image/')) {
                throw QuestionException::imageRejected('question_image_scope');
            }

            return;
        }
        if (!$institutionId instanceof Uuid) {
            throw QuestionException::imageRejected('question_image_scope');
        }
        $prefix = 'media/institution/'.$institutionId->toRfc4122().'/image/';
        if (!str_starts_with($storageKey, $prefix)) {
            throw QuestionException::imageRejected('question_image_scope');
        }
    }
}

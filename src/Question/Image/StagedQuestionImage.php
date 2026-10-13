<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Enum\StoredMediaStorageProvider;
use App\Exception\QuestionException;
use Symfony\Component\Uid\Uuid;

/**
 * One image object written for a question save that has not committed yet.
 */
final class StagedQuestionImage
{
    public function __construct(
        public readonly Uuid $assetId,
        public readonly string $storageKey,
        public readonly StoredMediaStorageProvider $provider,
        public readonly string $mime,
        public readonly int $byteSize,
        public readonly string $sha256,
    ) {
    }

    public function filename(): string
    {
        return match ($this->mime) {
            'image/jpeg' => 'soru.jpg',
            'image/png' => 'soru.png',
            'image/webp' => 'soru.webp',
            default => throw QuestionException::imageRejected('question_image_type'),
        };
    }
}

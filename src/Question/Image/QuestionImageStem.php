<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Question\Content\QuestionContentDocument;
use Symfony\Component\Uid\Uuid;

final class QuestionImageStem
{
    public static function append(QuestionContentDocument $stem, Uuid $mediaId): QuestionContentDocument
    {
        $blocks = $stem->blocks;
        $blocks[] = [
            'type' => 'image_reference',
            'mediaId' => $mediaId->toRfc4122(),
        ];

        return new QuestionContentDocument($stem->schemaVersion, $blocks);
    }
}

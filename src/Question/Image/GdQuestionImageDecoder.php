<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Exception\QuestionException;

final class GdQuestionImageDecoder implements QuestionImagePixelDecoder
{
    public function assertAvailable(): void
    {
        if (!\function_exists('getimagesizefromstring') || !\function_exists('imagecreatefromstring')) {
            throw QuestionException::imageRejected('question_image_decode');
        }
    }

    public function decode(string $bytes): array
    {
        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof \GdImage) {
            throw QuestionException::imageRejected('question_image_decode');
        }
        $width = imagesx($image);
        $height = imagesy($image);
        imagedestroy($image);

        return ['width' => $width, 'height' => $height];
    }
}

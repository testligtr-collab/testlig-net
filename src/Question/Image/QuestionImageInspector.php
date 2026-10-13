<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Exception\QuestionException;

/**
 * JPEG, PNG, and WebP question images. Header, MIME, and a real decode must all agree.
 */
final class QuestionImageInspector
{
    public const MAX_BYTES = 10_485_760;
    public const MAX_EDGE = 4096;
    public const MAX_PIXELS = 16_000_000;

    /**
     * @return array{mime: string, width: int, height: int, sha256: string}
     */
    public function inspect(string $bytes, ?string $declaredMime = null): array
    {
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw QuestionException::imageRejected('question_image_limit');
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            throw QuestionException::imageRejected('question_image_gif');
        }
        if ($this->looksLikeSvg($bytes)) {
            throw QuestionException::imageRejected('question_image_svg');
        }

        $format = $this->signatureFormat($bytes);
        $this->assertStill($format, $bytes);
        $detected = $this->detectedMime($bytes);
        $declared = null === $declaredMime ? null : strtolower(trim($declaredMime));
        if ($detected !== $format || (null !== $declared && '' !== $declared && $declared !== $format)) {
            throw QuestionException::imageRejected('question_image_type');
        }
        $size = @getimagesizefromstring($bytes);
        $image = @imagecreatefromstring($bytes);
        if (!\is_array($size) || false === $image) {
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }
            throw QuestionException::imageRejected('question_image_decode');
        }
        $width = imagesx($image);
        $height = imagesy($image);
        imagedestroy($image);
        if ($size[0] !== $width || $size[1] !== $height) {
            throw QuestionException::imageRejected('question_image_decode');
        }
        if ($width > self::MAX_EDGE || $height > self::MAX_EDGE || ($width * $height) > self::MAX_PIXELS) {
            throw QuestionException::imageRejected('question_image_limit');
        }

        return [
            'mime' => $format,
            'width' => $width,
            'height' => $height,
            'sha256' => hash('sha256', $bytes),
        ];
    }

    private function looksLikeSvg(string $bytes): bool
    {
        $head = strtolower(ltrim(substr($bytes, 0, 512)));

        return str_starts_with($head, '<svg')
            || str_contains($head, '<svg')
            || str_starts_with($head, '<?xml');
    }

    private function signatureFormat(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (\strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && 'WEBP' === substr($bytes, 8, 4)) {
            return 'image/webp';
        }

        throw QuestionException::imageRejected('question_image_type');
    }

    private function detectedMime(string $bytes): string
    {
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        if (!\is_string($mime)) {
            throw QuestionException::imageRejected('question_image_type');
        }
        $mime = strtolower($mime);
        if ('image/jpg' === $mime) {
            return 'image/jpeg';
        }

        return $mime;
    }

    private function assertStill(string $format, string $bytes): void
    {
        if ('image/jpeg' === $format) {
            $this->assertJpeg($bytes);

            return;
        }
        if ('image/png' === $format) {
            $this->assertPng($bytes);

            return;
        }
        $this->assertWebp($bytes);
    }

    private function assertJpeg(string $bytes): void
    {
        $length = \strlen($bytes);
        $offset = 2;
        while ($offset < $length) {
            if ("\xFF" !== $bytes[$offset]) {
                return;
            }
            while ($offset < $length && "\xFF" === $bytes[$offset]) {
                ++$offset;
            }
            if ($offset >= $length) {
                return;
            }
            $marker = \ord($bytes[$offset]);
            ++$offset;
            if (\in_array($marker, [0xD8, 0xD9, 0x01], true) || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            if (0xDA === $marker) {
                return;
            }
            if ($offset + 1 >= $length) {
                throw QuestionException::imageRejected('question_image_decode');
            }
            $segmentLength = (\ord($bytes[$offset]) << 8) + \ord($bytes[$offset + 1]);
            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                throw QuestionException::imageRejected('question_image_decode');
            }
            if (0xE1 === $marker && str_starts_with(substr($bytes, $offset + 2, $segmentLength - 2), "Exif\0\0")) {
                throw QuestionException::imageRejected('question_image_exif');
            }
            $offset += $segmentLength;
        }
    }

    private function assertPng(string $bytes): void
    {
        $length = \strlen($bytes);
        $offset = 8;
        while ($offset + 8 <= $length) {
            $chunkLength = unpack('N', substr($bytes, $offset, 4));
            $size = \is_array($chunkLength) ? (int) $chunkLength[1] : -1;
            $type = substr($bytes, $offset + 4, 4);
            if ($size < 0 || $offset + 12 + $size > $length) {
                throw QuestionException::imageRejected('question_image_decode');
            }
            if (\in_array($type, ['acTL', 'fcTL', 'fdAT'], true)) {
                throw QuestionException::imageRejected('question_image_animation');
            }
            if ('eXIf' === $type) {
                throw QuestionException::imageRejected('question_image_exif');
            }
            if ('IEND' === $type) {
                return;
            }
            $offset += 12 + $size;
        }
    }

    private function assertWebp(string $bytes): void
    {
        $length = \strlen($bytes);
        $offset = 12;
        while ($offset + 8 <= $length) {
            $id = substr($bytes, $offset, 4);
            if ('EXIF' === $id) {
                throw QuestionException::imageRejected('question_image_exif');
            }
            if (\in_array($id, ['ANIM', 'ANMF'], true)) {
                throw QuestionException::imageRejected('question_image_animation');
            }
            $chunkLength = unpack('V', substr($bytes, $offset + 4, 4));
            $size = \is_array($chunkLength) ? (int) $chunkLength[1] : -1;
            if ($size < 0 || $offset + 8 + $size > $length) {
                throw QuestionException::imageRejected('question_image_decode');
            }
            $offset += 8 + $size + ($size % 2);
        }
    }
}

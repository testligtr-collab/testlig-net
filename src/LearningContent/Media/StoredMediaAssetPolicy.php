<?php

declare(strict_types=1);

namespace App\LearningContent\Media;

use App\Enum\StoredMediaAssetKind;
use App\Exception\LearningContentException;

/**
 * MIME + extension allowlist and size limits per stored media kind.
 * Filename is display-only metadata; storage keys are app-generated separately.
 */
final class StoredMediaAssetPolicy
{
    private const SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * @var array<string, array{mimes: list<string>, extensions: list<string>, maxBytes: int}>
     */
    private const KIND_RULES = [
        'image' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            'maxBytes' => 10_485_760,
        ],
        'video' => [
            'mimes' => ['video/mp4', 'video/webm'],
            'extensions' => ['mp4', 'webm'],
            'maxBytes' => 524_288_000,
        ],
        'audio' => [
            'mimes' => ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav'],
            'extensions' => ['mp3', 'm4a', 'ogg', 'wav'],
            'maxBytes' => 104_857_600,
        ],
        'document' => [
            'mimes' => ['application/pdf', 'text/plain'],
            'extensions' => ['pdf', 'txt'],
            'maxBytes' => 52_428_800,
        ],
        'presentation' => [
            'mimes' => ['application/pdf', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'extensions' => ['pdf', 'pptx'],
            'maxBytes' => 104_857_600,
        ],
        'animation' => [
            'mimes' => ['video/mp4', 'image/gif', 'application/json'],
            'extensions' => ['mp4', 'gif', 'json'],
            'maxBytes' => 104_857_600,
        ],
        'interactive_package' => [
            'mimes' => ['application/zip', 'application/octet-stream'],
            'extensions' => ['zip'],
            'maxBytes' => 209_715_200,
        ],
        'subtitle' => [
            'mimes' => ['text/vtt', 'application/x-subrip', 'text/plain'],
            'extensions' => ['vtt', 'srt'],
            'maxBytes' => 1_048_576,
        ],
        'transcript' => [
            'mimes' => ['text/plain', 'text/vtt', 'application/json'],
            'extensions' => ['txt', 'vtt', 'json'],
            'maxBytes' => 5_242_880,
        ],
        'thumbnail' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'maxBytes' => 2_097_152,
        ],
    ];

    public function assertValidRegistration(
        StoredMediaAssetKind $kind,
        string $mimeType,
        string $originalFilename,
        int $byteSize,
        string $contentSha256,
    ): void {
        $rules = self::KIND_RULES[$kind->value];

        $mimeType = strtolower(trim($mimeType));
        if (!\in_array($mimeType, $rules['mimes'], true)) {
            throw LearningContentException::assetInvalid('MIME type is not allowed for media kind.');
        }

        if ($byteSize < 1 || $byteSize > $rules['maxBytes']) {
            throw LearningContentException::assetInvalid('Byte size is outside allowed range for media kind.');
        }

        if (1 !== preg_match(self::SHA256_PATTERN, $contentSha256)) {
            throw LearningContentException::assetInvalid('contentSha256 must be 64 lowercase hex characters.');
        }

        $extension = $this->extractExtension($originalFilename);
        if (null === $extension || !\in_array($extension, $rules['extensions'], true)) {
            throw LearningContentException::assetInvalid('Filename extension is not allowed for media kind.');
        }

        $this->assertSafeDisplayFilename($originalFilename);
    }

    public function maxBytesFor(StoredMediaAssetKind $kind): int
    {
        return self::KIND_RULES[$kind->value]['maxBytes'];
    }

    /**
     * @return list<string>
     */
    public function allowedMimesFor(StoredMediaAssetKind $kind): array
    {
        return self::KIND_RULES[$kind->value]['mimes'];
    }

    private function extractExtension(string $originalFilename): ?string
    {
        $base = basename(str_replace('\\', '/', $originalFilename));
        if (!str_contains($base, '.')) {
            return null;
        }
        $ext = strtolower(pathinfo($base, \PATHINFO_EXTENSION));

        return '' !== $ext ? $ext : null;
    }

    private function assertSafeDisplayFilename(string $originalFilename): void
    {
        $trimmed = trim($originalFilename);
        if ('' === $trimmed || mb_strlen($trimmed) > 255) {
            throw LearningContentException::assetInvalid('originalFilename must be 1-255 characters.');
        }
        if (
            str_contains($trimmed, "\0")
            || str_contains($trimmed, '/')
            || str_contains($trimmed, '\\')
            || str_contains($trimmed, '..')
        ) {
            throw LearningContentException::assetInvalid('originalFilename must not contain path separators or traversal.');
        }
    }
}

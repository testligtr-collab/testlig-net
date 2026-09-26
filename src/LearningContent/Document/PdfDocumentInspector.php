<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Exception\LearningContentException;

/**
 * Server-side PDF checks. The filename is display metadata, never a storage path.
 */
final class PdfDocumentInspector
{
    public const MAX_BYTES = 26_214_400;

    public const MESSAGE_TYPE = 'Yalnız PDF doküman kabul edilir.';

    public const MESSAGE_SIZE = 'PDF en fazla 25 MB olabilir.';

    public const MESSAGE_NAME = 'Dosya adı kullanılamıyor.';

    public function inspect(string $bytes, string $clientName): string
    {
        $length = \strlen($bytes);
        if ($length < 5 || $length > self::MAX_BYTES) {
            throw LearningContentException::assetInvalid($length > self::MAX_BYTES ? self::MESSAGE_SIZE : self::MESSAGE_TYPE);
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            throw LearningContentException::assetInvalid(self::MESSAGE_TYPE);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pdf');
        if (!\is_string($tmp)) {
            throw LearningContentException::assetInvalid(self::MESSAGE_TYPE);
        }
        try {
            if (false === file_put_contents($tmp, $bytes)) {
                throw LearningContentException::assetInvalid(self::MESSAGE_TYPE);
            }
            $finfo = new \finfo(\FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp);
            if ('application/pdf' !== $mime) {
                throw LearningContentException::assetInvalid(self::MESSAGE_TYPE);
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }

        return $this->displayName($clientName);
    }

    public function displayName(string $clientName): string
    {
        $name = basename(str_replace('\\', '/', $clientName));
        if (str_contains($name, "\0") || str_contains($name, '..')) {
            throw LearningContentException::assetInvalid(self::MESSAGE_NAME);
        }
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{0,120}\.pdf$/', $name)) {
            throw LearningContentException::assetInvalid(self::MESSAGE_NAME);
        }
        if (1 !== substr_count($name, '.')) {
            throw LearningContentException::assetInvalid(self::MESSAGE_NAME);
        }

        return $name;
    }

    public static function sizeLabel(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return intdiv($bytes, 1024).' KB';
    }
}

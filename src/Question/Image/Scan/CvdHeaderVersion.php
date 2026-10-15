<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * Reads the first 512-byte ClamAV CVD header. The version is the third colon field.
 */
final class CvdHeaderVersion
{
    public static function parse(string $header): ?int
    {
        if (\strlen($header) < 12 || !str_starts_with($header, 'ClamAV-VDB:')) {
            return null;
        }
        $end = strpos($header, "\0");
        $line = \is_int($end) ? substr($header, 0, $end) : $header;
        $newline = strpos($line, "\n");
        if (\is_int($newline)) {
            $line = substr($line, 0, $newline);
        }
        $fields = explode(':', $line);
        if (\count($fields) < 3) {
            return null;
        }

        return ClamavPublishedDatabaseVersion::canonical($fields[2]);
    }
}

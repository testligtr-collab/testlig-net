<?php

declare(strict_types=1);

namespace App\Release;

/**
 * The full Git SHA a release was packaged from.
 *
 * It is read once, while the container of that release is compiled, from the REVISION file the deploy
 * workflow puts into the release tarball. The value is never read again per request, so a web process that
 * still runs older code of another release cannot report a newer SHA.
 */
final class ReleaseRevision
{
    public const HEADER = 'X-Testlig-Release';

    private const FORMAT = '/^[0-9a-f]{40}$/D';

    private function __construct()
    {
    }

    /**
     * Returns the SHA when the content is exactly one lowercase 40-character hex revision, otherwise null.
     * A missing, empty, short, uppercase, multi-line or otherwise unexpected value is never repaired.
     */
    public static function parse(string $content): ?string
    {
        $value = rtrim($content, "\r\n");

        return 1 === preg_match(self::FORMAT, $value) ? $value : null;
    }

    public static function fromFile(string $file): ?string
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $content = file_get_contents($file, false, null, 0, 128);

        return false === $content ? null : self::parse($content);
    }
}

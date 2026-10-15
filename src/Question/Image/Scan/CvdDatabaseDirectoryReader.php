<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * Local main, daily, and bytecode versions from the configured CVD directory.
 * An empty directory fails closed and does not open a file.
 */
final class CvdDatabaseDirectoryReader implements ClamavLocalDatabaseVersions
{
    public const HEADER_BYTES = 512;
    public const TIMEOUT_SECONDS = 2;

    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * @return ?array{main: int, daily: int, bytecode: int}
     */
    public function read(): ?array
    {
        if (1 !== preg_match('#^/(?:[A-Za-z0-9._-]{1,64}/){0,16}[A-Za-z0-9._-]{1,64}$#', $this->directory)) {
            return null;
        }
        $main = $this->version('main');
        $daily = $this->version('daily');
        $bytecode = $this->version('bytecode');
        if (null === $main || null === $daily || null === $bytecode) {
            return null;
        }

        return ['main' => $main, 'daily' => $daily, 'bytecode' => $bytecode];
    }

    private function version(string $name): ?int
    {
        $found = [];
        foreach (['cvd', 'cld'] as $extension) {
            $path = $this->directory.'/'.$name.'.'.$extension;
            if (!is_file($path)) {
                continue;
            }
            $header = $this->header($path);
            $version = \is_string($header) ? CvdHeaderVersion::parse($header) : null;
            if (null === $version) {
                return null;
            }
            $found[] = $version;
        }
        if ([] === $found) {
            return null;
        }
        $first = $found[0];
        foreach ($found as $version) {
            if ($version !== $first) {
                return null;
            }
        }

        return $first;
    }

    private function header(string $path): ?string
    {
        $handle = fopen($path, 'r');
        if (!\is_resource($handle)) {
            return null;
        }
        stream_set_timeout($handle, self::TIMEOUT_SECONDS);
        try {
            $header = fread($handle, self::HEADER_BYTES);
            $meta = stream_get_meta_data($handle);
        } finally {
            fclose($handle);
        }
        if (!\is_string($header) || self::HEADER_BYTES !== \strlen($header)) {
            return null;
        }
        if ($meta['timed_out']) {
            return null;
        }

        return $header;
    }
}

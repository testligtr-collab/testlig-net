<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Resolves and validates private homework PDF durable and quarantine paths.
 * Production release deployments must place both roots outside releases/ and public/.
 */
final class HomeworkPdfStoragePaths
{
    public function __construct(
        private readonly string $durableConfigured,
        private readonly string $quarantineConfigured,
        private readonly string $projectDir,
    ) {
    }

    public function durableDirectory(): string
    {
        $configured = trim($this->durableConfigured);
        if ('' !== $configured) {
            return $this->trimTrailingSeparators($configured);
        }

        return $this->trimTrailingSeparators($this->projectDir).\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'homework-pdf';
    }

    public function quarantineDirectory(): string
    {
        $configured = trim($this->quarantineConfigured);
        if ('' !== $configured) {
            return $this->trimTrailingSeparators($configured);
        }

        return $this->trimTrailingSeparators($this->projectDir).\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'homework-pdf-quarantine';
    }

    public function isSafeDurableDirectoryReady(): bool
    {
        $durable = $this->durableDirectory();
        $quarantine = $this->quarantineDirectory();

        return $this->isValidRootCandidate($durable)
            && !$this->isReleaseScopedPath($durable)
            && !$this->isInsidePublicDir($durable)
            && !$this->pathsOverlap($durable, $quarantine)
            && $this->isWritableDirectoryOrParent($durable);
    }

    public function isSafeQuarantineDirectoryReady(): bool
    {
        $durable = $this->durableDirectory();
        $quarantine = $this->quarantineDirectory();

        return $this->isValidRootCandidate($quarantine)
            && !$this->isReleaseScopedPath($quarantine)
            && !$this->isInsidePublicDir($quarantine)
            && !$this->pathsOverlap($durable, $quarantine)
            && $this->isWritableDirectoryOrParent($quarantine);
    }

    public function isReleaseScopedPath(string $path): bool
    {
        if (!$this->isValidRootCandidate($path)) {
            return true;
        }
        $normalized = $this->normalizeSlashes($path);
        if (1 === preg_match('#(?:^|/)releases/[^/]+(?:/|$)#', $normalized)) {
            return true;
        }
        $resolved = $this->resolveTargetPath($path);
        if (null === $resolved) {
            return true;
        }
        if (1 === preg_match('#(?:^|/)releases/[^/]+(?:/|$)#', $resolved)) {
            return true;
        }
        $resolvedProject = realpath($this->projectDir);
        if (\is_string($resolvedProject)) {
            $projectNorm = $this->normalizeSlashes($resolvedProject);
            if (1 === preg_match('#(?:^|/)releases/[^/]+$#', $projectNorm)) {
                if ($resolved === $projectNorm || str_starts_with($resolved, $projectNorm.'/')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isInsidePublicDir(string $path): bool
    {
        $resolved = $this->resolveTargetPath($path);
        if (null === $resolved) {
            return true;
        }
        $public = $this->resolveTargetPath($this->trimTrailingSeparators($this->projectDir).\DIRECTORY_SEPARATOR.'public');
        if (null !== $public && ($resolved === $public || str_starts_with($resolved, $public.'/'))) {
            return true;
        }
        $resolvedProject = realpath($this->projectDir);
        if (\is_string($resolvedProject)) {
            $publicHtml = $this->normalizeSlashes(\dirname($resolvedProject)).'/public_html';
            if ($resolved === $publicHtml || str_starts_with($resolved, $publicHtml.'/')) {
                return true;
            }
        }

        return 1 === preg_match('#/(?:public|public_html)(?:/|$)#', $resolved);
    }

    public function pathsOverlap(string $first, string $second): bool
    {
        $resolvedFirst = $this->resolveTargetPath($first) ?? $this->normalizeSlashes($first);
        $resolvedSecond = $this->resolveTargetPath($second) ?? $this->normalizeSlashes($second);

        return $resolvedFirst === $resolvedSecond
            || str_starts_with($resolvedFirst, $resolvedSecond.'/')
            || str_starts_with($resolvedSecond, $resolvedFirst.'/');
    }

    private function isValidRootCandidate(string $path): bool
    {
        if ('' === $path || str_contains($path, "\0")) {
            return false;
        }
        if (!$this->isAbsolutePath($path)) {
            return false;
        }
        $segments = explode('/', $this->normalizeSlashes($path));
        foreach ($segments as $segment) {
            if ('..' === $segment || '.' === $segment) {
                return false;
            }
        }

        return true;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return '' !== trim($path, '/\\');
        }

        return 1 === preg_match('#^[A-Za-z]:[/\\\\].+$#', $path);
    }

    private function isWritableDirectoryOrParent(string $path): bool
    {
        if (is_link($path)) {
            return false;
        }
        if (file_exists($path)) {
            return is_dir($path) && is_writable($path);
        }
        $cursor = $path;
        while (true) {
            $parent = \dirname($cursor);
            if ($parent === $cursor) {
                return false;
            }
            $cursor = $parent;
            if (is_link($cursor)) {
                $resolved = realpath($cursor);
                if (!\is_string($resolved) || !is_dir($resolved) || !is_writable($resolved)) {
                    return false;
                }

                return true;
            }
            if (file_exists($cursor)) {
                return is_dir($cursor) && is_writable($cursor);
            }
        }
    }

    private function resolveTargetPath(string $path): ?string
    {
        if (!$this->isValidRootCandidate($path)) {
            return null;
        }
        $real = realpath($path);
        if (\is_string($real)) {
            return $this->normalizeSlashes($real);
        }
        $pending = [];
        $cursor = $this->trimTrailingSeparators($path);
        while (true) {
            $realAncestor = realpath($cursor);
            if (\is_string($realAncestor)) {
                $base = $this->normalizeSlashes($realAncestor);
                if ([] === $pending) {
                    return $base;
                }

                return rtrim($base, '/').'/'.implode('/', array_reverse($pending));
            }
            $parent = \dirname($cursor);
            if ($parent === $cursor) {
                return null;
            }
            $baseName = basename($cursor);
            if ('' === $baseName || '.' === $baseName || '..' === $baseName) {
                return null;
            }
            $pending[] = $baseName;
            $cursor = $parent;
        }
    }

    private function trimTrailingSeparators(string $path): string
    {
        if ('/' === $path || '\\' === $path) {
            return $path;
        }

        return rtrim($path, '/\\');
    }

    private function normalizeSlashes(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return \strlen($normalized) > 1 ? rtrim($normalized, '/') : $normalized;
    }
}

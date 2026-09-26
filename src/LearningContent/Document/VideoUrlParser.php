<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

use App\Exception\LearningContentException;

/**
 * Parses a public video URL. It never requests the URL.
 */
final class VideoUrlParser
{
    public const MESSAGE = 'Video bağlantısı geçerli bir YouTube veya Vimeo adresi olmalı.';

    private const HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'youtu.be',
        'youtube-nocookie.com',
        'vimeo.com',
        'www.vimeo.com',
    ];

    public function parse(string $raw): VideoEmbed
    {
        $trimmed = trim($raw);
        if ('' === $trimmed || str_contains($trimmed, '<') || str_contains($trimmed, '>') || str_contains($trimmed, 'iframe')) {
            throw LearningContentException::contentInvalid(self::MESSAGE);
        }
        if (preg_match('/\s/u', $trimmed)) {
            throw LearningContentException::contentInvalid(self::MESSAGE);
        }

        $parts = parse_url($trimmed);
        if (!\is_array($parts)) {
            throw LearningContentException::contentInvalid(self::MESSAGE);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ('https' !== $scheme || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw LearningContentException::contentInvalid(self::MESSAGE);
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = rtrim($host, '.');
        if (!\in_array($host, self::HOSTS, true)) {
            throw LearningContentException::contentInvalid(self::MESSAGE);
        }

        $embed = str_contains($host, 'vimeo')
            ? $this->vimeo((string) ($parts['path'] ?? ''))
            : $this->youtube($host, (string) ($parts['path'] ?? ''), (string) ($parts['query'] ?? ''));
        if (!$embed instanceof VideoEmbed) {
            throw LearningContentException::contentInvalid(self::MESSAGE);
        }

        return $embed;
    }

    public static function isProviderId(string $provider, string $id): bool
    {
        if ('youtube' === $provider) {
            return 1 === preg_match('/^[A-Za-z0-9_-]{11}$/', $id);
        }
        if ('vimeo' === $provider) {
            return 1 === preg_match('/^[0-9]{6,12}$/', $id);
        }

        return false;
    }

    private function youtube(string $host, string $path, string $query): ?VideoEmbed
    {
        if ('youtu.be' === $host) {
            $id = $this->singleSegment($path);

            return \is_string($id) && self::isProviderId('youtube', $id) ? new VideoEmbed('youtube', $id) : null;
        }
        if ('youtube-nocookie.com' === $host) {
            if (1 !== preg_match('#^/embed/([A-Za-z0-9_-]{11})$#', $path, $match)) {
                return null;
            }

            return new VideoEmbed('youtube', $match[1]);
        }
        if (1 === preg_match('#^/(embed|shorts|live|v)/([A-Za-z0-9_-]{11})$#', $path, $match)) {
            return new VideoEmbed('youtube', $match[2]);
        }
        if ('/watch' !== $path) {
            return null;
        }
        parse_str($query, $params);
        $id = $params['v'] ?? null;
        if (!\is_string($id) || !self::isProviderId('youtube', $id)) {
            return null;
        }

        return new VideoEmbed('youtube', $id);
    }

    private function vimeo(string $path): ?VideoEmbed
    {
        if (1 === preg_match('#^/(\d{6,12})$#', $path, $match) || 1 === preg_match('#^/video/(\d{6,12})$#', $path, $match)) {
            return new VideoEmbed('vimeo', $match[1]);
        }

        return null;
    }

    private function singleSegment(string $path): ?string
    {
        $trimmed = trim($path, '/');
        if ('' === $trimmed || str_contains($trimmed, '/')) {
            return null;
        }

        return $trimmed;
    }
}

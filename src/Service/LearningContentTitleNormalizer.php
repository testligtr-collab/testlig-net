<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\LearningContentException;

/**
 * Central title / slug normalizer for learning content identities.
 */
final class LearningContentTitleNormalizer
{
    /**
     * @return array{title: string, normalizedTitle: string, slug: string}
     */
    public function normalize(string $title): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        if ('' === $title || mb_strlen($title) > 200) {
            throw LearningContentException::invalidInput('Title must be 1-200 characters.');
        }
        if (1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $title)) {
            throw LearningContentException::invalidInput('Title must not contain control characters.');
        }

        $normalizedTitle = mb_strtolower($title, 'UTF-8');
        $slug = $this->slugify($normalizedTitle);
        if ('' === $slug || \strlen($slug) > 200) {
            throw LearningContentException::invalidInput('Title cannot produce a valid slug.');
        }

        return [
            'title' => $title,
            'normalizedTitle' => $normalizedTitle,
            'slug' => $slug,
        ];
    }

    public function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (false !== $transliterated && '' !== $transliterated) {
            $value = $transliterated;
        }
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    public function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code)) {
            throw LearningContentException::invalidInput('Code must match ^[a-z][a-z0-9_]{1,63}$.');
        }

        return $code;
    }

    public function normalizeSummary(?string $summary): ?string
    {
        if (null === $summary) {
            return null;
        }
        $summary = trim(preg_replace('/\s+/u', ' ', $summary) ?? $summary);
        if ('' === $summary) {
            return null;
        }
        if (mb_strlen($summary) > 2000) {
            throw LearningContentException::invalidInput('Summary must be at most 2000 characters.');
        }
        if (1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $summary)) {
            throw LearningContentException::invalidInput('Summary must not contain control characters.');
        }

        return $summary;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\InstitutionOperationException;

/**
 * Produces display name, normalized search key, and URL-safe slug for institutions.
 */
final class InstitutionNameNormalizer
{
    /**
     * @return array{name: string, normalizedName: string, slug: string}
     */
    public function normalize(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ('' === $name || mb_strlen($name) > 180) {
            throw InstitutionOperationException::invalidInput('Institution name must be 1-180 characters.');
        }

        $normalizedName = mb_strtolower($name, 'UTF-8');
        $slug = $this->slugify($normalizedName);
        if ('' === $slug || \strlen($slug) > 180) {
            throw InstitutionOperationException::invalidInput('Institution name cannot produce a valid slug.');
        }

        return [
            'name' => $name,
            'normalizedName' => $normalizedName,
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
        $value = trim($value, '-');

        return $value;
    }
}

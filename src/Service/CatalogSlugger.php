<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\CatalogException;

/**
 * Deterministic URL-safe slugs for catalog names (Turkish-aware via ASCII transliteration).
 */
final class CatalogSlugger
{
    public function __construct(
        private readonly InstitutionNameNormalizer $names,
    ) {
    }

    private const TR_ASCII = [
        'ş' => 's', 'Ş' => 's', 'ı' => 'i', 'İ' => 'i', 'ğ' => 'g', 'Ğ' => 'g',
        'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
    ];

    public function slugify(string $value, int $maxLength = 160): string
    {
        // Explicit Turkish map before iconv so CI/Windows locales stay deterministic.
        $slug = $this->names->slugify(strtr($value, self::TR_ASCII));
        if ('' === $slug) {
            throw CatalogException::invalidInput('Ad geçerli bir kısa adres (slug) üretmiyor.');
        }
        if (\strlen($slug) > $maxLength) {
            $slug = rtrim(substr($slug, 0, $maxLength), '-');
        }
        if ('' === $slug) {
            throw CatalogException::invalidInput('Ad geçerli bir kısa adres (slug) üretmiyor.');
        }

        return $slug;
    }
}

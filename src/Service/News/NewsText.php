<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Exception\NewsArticleException;

final class NewsText
{
    public static function plain(string $value, int $min, int $max, bool $multiline): string
    {
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            throw NewsArticleException::invalidInput();
        }
        if (1 !== preg_match('//u', $value)) {
            throw NewsArticleException::invalidInput();
        }
        if (str_contains($value, '<') || str_contains($value, '>')) {
            throw NewsArticleException::invalidInput();
        }
        $pattern = $multiline
            ? '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/'
            : '/[\\x00-\\x1F\\x7F]/';
        if (1 === preg_match($pattern, $value)) {
            throw NewsArticleException::invalidInput();
        }

        return $value;
    }

    public static function slug(string $value, string $fallback): string
    {
        $map = [
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
            'Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'I' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u',
        ];
        $slug = mb_strtolower(strtr($value, $map));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ('' === $slug) {
            $slug = $fallback;
        }

        return mb_substr($slug, 0, 80);
    }
}

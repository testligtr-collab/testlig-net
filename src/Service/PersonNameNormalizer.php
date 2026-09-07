<?php

declare(strict_types=1);

namespace App\Service;

final class PersonNameNormalizer
{
    public function normalize(string $name, string $field = 'name'): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ('' === $normalized) {
            throw new \InvalidArgumentException(\sprintf('%s cannot be empty.', ucfirst($field)));
        }

        if (mb_strlen($normalized) > 100) {
            throw new \InvalidArgumentException(\sprintf('%s must be at most 100 characters.', ucfirst($field)));
        }

        return $normalized;
    }
}

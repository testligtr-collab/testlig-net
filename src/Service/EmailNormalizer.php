<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Central email canonicalization for identity (login) and display storage.
 */
final class EmailNormalizer
{
    /**
     * @return array{email: string, normalizedEmail: string}
     */
    public function normalizePair(string $email): array
    {
        $display = trim($email);

        if ('' === $display) {
            throw new \InvalidArgumentException('Email cannot be empty.');
        }

        if (mb_strlen($display) > 180) {
            throw new \InvalidArgumentException('Email must be at most 180 characters.');
        }

        $normalized = mb_strtolower($display, 'UTF-8');

        if (false === filter_var($normalized, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email format is invalid.');
        }

        return [
            'email' => $display,
            'normalizedEmail' => $normalized,
        ];
    }

    public function normalize(string $email): string
    {
        return $this->normalizePair($email)['normalizedEmail'];
    }
}

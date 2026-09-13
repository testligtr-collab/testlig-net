<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Exception\CommerceException;

/**
 * Shared normalization for commerce manager inputs.
 *
 * Keeps reason codes, offer codes, and display text in the same closed shapes the DB
 * CHECK constraints enforce, so managers cannot drift from the schema.
 */
final class CommerceInputNormalizer
{
    public static function reasonCode(string $reasonCode): string
    {
        $reasonCode = strtolower(trim($reasonCode));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw CommerceException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }

    public static function offerCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code)) {
            throw CommerceException::invalidInput('Offer code must match ^[a-z][a-z0-9_]{1,63}$.');
        }

        return $code;
    }

    public static function providerCode(string $providerCode): string
    {
        $providerCode = strtolower(trim($providerCode));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,31}$/', $providerCode)) {
            throw CommerceException::invalidInput('providerCode must be snake_case (2-32 characters).');
        }

        return $providerCode;
    }

    public static function displayName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ('' === $name || mb_strlen($name) > 200) {
            throw CommerceException::invalidInput('Offer name must be 1-200 characters.');
        }
        if (1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $name)) {
            throw CommerceException::invalidInput('Offer name must not contain control characters.');
        }

        return $name;
    }

    public static function description(?string $description): ?string
    {
        if (null === $description) {
            return null;
        }
        $description = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
        if ('' === $description) {
            return null;
        }
        if (mb_strlen($description) > 4000) {
            throw CommerceException::invalidInput('Description must be at most 4000 characters.');
        }

        return $description;
    }

    public static function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw CommerceException::invalidInput('currency must be an ISO 4217 alpha-3 code.');
        }

        return $currency;
    }
}

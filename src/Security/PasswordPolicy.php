<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Single source of truth for public/account password requirements.
 *
 * Length only (8–128 Unicode characters). No case/digit/symbol mix rules.
 * Does not trim or mutate passwords — form fields must set trim=false.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 128;

    public const TOO_SHORT_MESSAGE = 'Parolanız en az 8 karakter olmalıdır.';
    public const TOO_LONG_MESSAGE = 'Parolanız en fazla 128 karakter olabilir.';
    public const HELP_TEXT = 'En az 8 karakter kullanın.';
    public const COMPROMISED_MESSAGE = 'Bu parola bilinen bir veri ihlalinde görülmüş. Lütfen başka bir parola seçin.';

    private function __construct()
    {
    }

    /**
     * Core constraints shared by registration, reset, and change-password DTOs.
     *
     * @return list<Constraint>
     */
    public static function constraints(string $notBlankMessage = 'Parola zorunludur.'): array
    {
        return [
            new Assert\NotBlank(message: $notBlankMessage),
            new Assert\Length(
                min: self::MIN_LENGTH,
                max: self::MAX_LENGTH,
                minMessage: self::TOO_SHORT_MESSAGE,
                maxMessage: self::TOO_LONG_MESSAGE,
                charset: 'UTF-8',
            ),
        ];
    }

    /**
     * Optional HIBP check — skipped in the test environment by callers.
     */
    public static function notCompromisedConstraint(): Assert\NotCompromisedPassword
    {
        return new Assert\NotCompromisedPassword(message: self::COMPROMISED_MESSAGE);
    }
}

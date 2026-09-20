<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Invalid phone input. Messages must never include the raw phone number.
 */
final class PhoneNormalizationException extends \InvalidArgumentException
{
    public static function invalid(): self
    {
        return new self('Geçerli bir Türkiye cep telefonu numarası girin.');
    }
}

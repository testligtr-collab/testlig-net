<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Turkish labels for the institution-application reject codes the product already stores.
 *
 * The manager still accepts its existing pattern. This catalog is the admin form contract.
 */
final class InstitutionApplicationRejectReason
{
    public const LEGACY_LABEL = 'Diğer / eski kayıt';

    /**
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            'incomplete_documents' => 'Eksik belgeler',
        ];
    }

    public static function isAllowed(string $code): bool
    {
        return \array_key_exists($code, self::choices());
    }

    public static function label(?string $code): string
    {
        if (null === $code || '' === $code) {
            return 'Kayıtlı gerekçe yok';
        }

        return self::choices()[$code] ?? self::LEGACY_LABEL;
    }

    public static function invalidMessage(string $posted): string
    {
        return '' === $posted
            ? 'Ret nedeni seçin.'
            : 'Seçilen ret nedeni geçersiz.';
    }
}

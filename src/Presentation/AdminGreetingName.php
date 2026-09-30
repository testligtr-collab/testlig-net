<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Chooses whether a stored first name is safe to speak in the admin greeting.
 *
 * Empty values, addresses, identifiers, and this product's role placeholders
 * are not personal names. The bootstrap command stores "Super" as a first name;
 * that record is left untouched and the greeting omits it.
 */
final class AdminGreetingName
{
    /**
     * @var list<string>
     */
    private const ROLE_TOKENS = [
        'super',
        'admin',
        'superadmin',
        'super admin',
        'süper',
        'süper yönetici',
        'yonetici',
        'yönetici',
    ];

    public function friendlyFirstName(string $firstName): ?string
    {
        $name = trim($firstName);
        if ('' === $name || str_contains($name, '@') || str_contains(strtoupper($name), 'ROLE_')) {
            return null;
        }
        if (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name)) {
            return null;
        }
        if (\in_array(mb_strtolower($name), self::ROLE_TOKENS, true)) {
            return null;
        }

        return $name;
    }
}

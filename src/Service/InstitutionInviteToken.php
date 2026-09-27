<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Shared 256-bit base64url token for institution invites. Plaintext is never stored.
 */
final class InstitutionInviteToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}

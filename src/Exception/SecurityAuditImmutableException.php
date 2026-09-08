<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Raised when application code attempts to mutate or delete an audit event.
 */
final class SecurityAuditImmutableException extends \RuntimeException
{
    public static function updateForbidden(): self
    {
        return new self('Security audit events are append-only and cannot be updated.');
    }

    public static function deleteForbidden(): self
    {
        return new self('Security audit events are append-only and cannot be deleted through the application.');
    }
}

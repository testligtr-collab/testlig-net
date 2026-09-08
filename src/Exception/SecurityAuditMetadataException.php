<?php

declare(strict_types=1);

namespace App\Exception;

final class SecurityAuditMetadataException extends \InvalidArgumentException
{
    public static function forbiddenKey(string $key): self
    {
        return new self(\sprintf('Security audit metadata key "%s" is not allowed.', $key));
    }

    public static function unsupportedValue(string $key): self
    {
        return new self(\sprintf('Security audit metadata key "%s" has an unsupported value type.', $key));
    }
}

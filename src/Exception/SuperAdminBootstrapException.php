<?php

declare(strict_types=1);

namespace App\Exception;

final class SuperAdminBootstrapException extends \RuntimeException
{
    public static function disabled(): self
    {
        return new self('Super-admin bootstrap is disabled. Set ALLOW_SUPER_ADMIN_BOOTSTRAP=1 temporarily to enable it.');
    }

    public static function confirmationRequired(): self
    {
        return new self('Refusing to bootstrap without explicit --confirm.');
    }

    public static function alreadyExists(): self
    {
        return new self('A ROLE_SUPER_ADMIN account already exists. Bootstrap is one-shot only.');
    }

    public static function emailTaken(): self
    {
        return new self('A user with this e-mail already exists. Bootstrap will not promote or take over existing accounts.');
    }

    public static function lockBusy(): self
    {
        return new self('Another super-admin bootstrap is already in progress. Try again later.');
    }

    public static function weakPassword(string $message): self
    {
        return new self($message);
    }
}

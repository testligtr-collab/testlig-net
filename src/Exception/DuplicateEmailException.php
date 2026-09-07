<?php

declare(strict_types=1);

namespace App\Exception;

final class DuplicateEmailException extends \RuntimeException
{
    public function __construct(string $normalizedEmail)
    {
        parent::__construct(\sprintf('A user with email "%s" already exists.', $normalizedEmail));
    }
}

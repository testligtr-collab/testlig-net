<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\User;

final readonly class RegistrationResult
{
    public function __construct(
        public User $user,
        public bool $verificationEmailDispatched,
    ) {
    }
}

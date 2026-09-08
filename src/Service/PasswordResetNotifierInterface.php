<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

interface PasswordResetNotifierInterface
{
    public function sendResetEmail(User $user, ResetPasswordToken $resetToken): void;
}

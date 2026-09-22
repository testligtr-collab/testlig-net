<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\Constraints\UserPassword;

/**
 * New password after a validated reset token.
 */
final class ResetPasswordRequestData
{
    #[UserPassword]
    public string $plainPassword = '';
}

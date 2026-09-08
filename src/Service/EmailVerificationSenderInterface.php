<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * Sends or re-requests e-mail verification messages.
 */
interface EmailVerificationSenderInterface
{
    public function sendVerificationEmail(User $user): void;

    public function requestResend(string $email): void;
}

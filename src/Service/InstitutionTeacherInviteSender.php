<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

interface InstitutionTeacherInviteSender
{
    /**
     * @throws TransportExceptionInterface
     */
    public function send(string $recipientEmail, string $institutionName, \DateTimeImmutable $expiresAt, string $plainToken): void;
}

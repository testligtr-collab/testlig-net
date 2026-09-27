<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

interface InstitutionStudentInviteSender
{
    /**
     * @throws TransportExceptionInterface
     */
    public function send(
        string $recipientEmail,
        string $institutionName,
        string $classroomName,
        \DateTimeImmutable $expiresAt,
        string $plainToken,
    ): void;
}

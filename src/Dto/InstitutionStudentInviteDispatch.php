<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionStudentInviteDispatch
{
    public function __construct(
        public bool $send,
        public string $recipientEmail,
        public string $institutionName,
        public string $classroomName,
        public \DateTimeImmutable $expiresAt,
        public string $plainToken,
    ) {
    }

    public static function silent(): self
    {
        return new self(false, '', '', '', new \DateTimeImmutable('@0'), '');
    }
}

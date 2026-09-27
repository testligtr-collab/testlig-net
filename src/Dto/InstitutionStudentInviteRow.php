<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class InstitutionStudentInviteRow
{
    public function __construct(
        public string $reference,
        public string $maskedEmail,
        public string $statusLabel,
        public string $createdAtLabel,
        public string $expiresAtLabel,
        public bool $canResend,
        public bool $canRevoke,
    ) {
    }
}

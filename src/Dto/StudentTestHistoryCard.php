<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One owned test on the student history page. No internal ids.
 */
final readonly class StudentTestHistoryCard
{
    public function __construct(
        public string $code,
        public string $title,
        public string $subject,
        public string $state,
        public string $stateLabel,
        public ?string $completedAt,
        public ?int $correct,
        public ?int $incorrect,
        public ?int $unanswered,
        public ?string $earned,
        public ?string $total,
        public ?string $percentage,
    ) {
    }
}

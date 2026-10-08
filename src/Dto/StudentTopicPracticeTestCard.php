<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Self-serve practice test on a catalog topic page (public assessment code only).
 */
final readonly class StudentTopicPracticeTestCard
{
    public function __construct(
        public string $title,
        public string $code,
        public int $questionCount,
        public string $durationLabel,
    ) {
    }
}

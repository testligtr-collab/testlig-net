<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * System health page projection. Labels only — never DSN/exception text.
 */
final class AdminSystemHealthView
{
    /**
     * @param array{due: int, retry_pending: int, processing: int, stale_lease: int, dead_letter: int}|null $webhookQueueSummary
     */
    public function __construct(
        public readonly string $applicationStatus,
        public readonly string $databaseStatus,
        public readonly string $redisStatus,
        public readonly string $migrationStatus,
        public readonly string $utcNote,
        public readonly ?array $webhookQueueSummary,
    ) {
    }
}

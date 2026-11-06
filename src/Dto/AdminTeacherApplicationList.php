<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One filtered page of teacher applications.
 */
final class AdminTeacherApplicationList
{
    /**
     * @param AdminPagedResult<AdminTeacherApplicationRow> $result
     * @param array{q: string, status: string}             $filters
     */
    public function __construct(
        public readonly AdminPagedResult $result,
        public readonly array $filters,
    ) {
    }
}

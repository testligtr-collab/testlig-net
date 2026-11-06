<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One filtered page of institution applications.
 */
final class AdminInstitutionApplicationList
{
    /**
     * @param AdminPagedResult<AdminInstitutionApplicationRow> $result
     * @param array{q: string, status: string}                 $filters
     */
    public function __construct(
        public readonly AdminPagedResult $result,
        public readonly array $filters,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\UserRole;

final class AdminTeacherDirectoryList
{
    /**
     * @param AdminPagedResult<AdminTeacherDirectoryRow>                      $result
     * @param array{q: string, status: string, role: string, subject: string} $filters
     * @param list<array{id: string, name: string}>                           $subjects
     * @param list<UserRole>                                                  $roleChoices
     */
    public function __construct(
        public readonly AdminPagedResult $result,
        public readonly array $filters,
        public readonly array $subjects,
        public readonly array $roleChoices,
    ) {
    }
}

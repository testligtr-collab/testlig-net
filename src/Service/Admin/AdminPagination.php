<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * Shared pagination bounds for admin list endpoints.
 */
final class AdminPagination
{
    public const DEFAULT_PAGE_SIZE = 25;
    public const MAX_PAGE_SIZE = 100;

    public static function normalizePage(int $page): int
    {
        return max(1, $page);
    }

    public static function normalizePageSize(int $pageSize): int
    {
        if ($pageSize < 1) {
            return self::DEFAULT_PAGE_SIZE;
        }

        return min($pageSize, self::MAX_PAGE_SIZE);
    }

    public static function offset(int $page, int $pageSize): int
    {
        return ($page - 1) * $pageSize;
    }
}

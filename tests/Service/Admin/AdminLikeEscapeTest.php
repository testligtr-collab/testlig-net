<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Service\Admin\AdminLikeEscape;
use PHPUnit\Framework\TestCase;

final class AdminLikeEscapeTest extends TestCase
{
    public function testEscapesWildcardsAndBackslash(): void
    {
        self::assertSame('a!!b!%c!_d', AdminLikeEscape::escape('a!b%c_d'));
        self::assertSame('%foo!%bar%', AdminLikeEscape::containsPattern('foo%bar'));
        self::assertSame('!', AdminLikeEscape::escapeClause());
    }

    public function testNormalizeSearchRejectsTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AdminLikeEscape::normalizeSearch(str_repeat('a', AdminLikeEscape::MAX_QUERY_LENGTH + 1));
    }

    public function testNormalizeSearchTrimsEmptyToNull(): void
    {
        self::assertNull(AdminLikeEscape::normalizeSearch('   '));
        self::assertSame('ok', AdminLikeEscape::normalizeSearch(' ok '));
    }
}

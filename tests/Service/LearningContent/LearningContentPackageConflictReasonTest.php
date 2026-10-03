<?php

declare(strict_types=1);

namespace App\Tests\Service\LearningContent;

use App\Service\LearningContent\LearningContentPackageConflictReason;
use PHPUnit\Framework\TestCase;

final class LearningContentPackageConflictReasonTest extends TestCase
{
    public function testUniqueSortedIsDeterministicAndClosed(): void
    {
        $sorted = LearningContentPackageConflictReason::uniqueSorted([
            LearningContentPackageConflictReason::SUMMARY_MISMATCH,
            LearningContentPackageConflictReason::ACTOR_NOT_OWNER,
            LearningContentPackageConflictReason::SUMMARY_MISMATCH,
            'email_dump',
            LearningContentPackageConflictReason::TITLE_MISMATCH,
        ]);

        self::assertSame([
            LearningContentPackageConflictReason::ACTOR_NOT_OWNER,
            LearningContentPackageConflictReason::TITLE_MISMATCH,
            LearningContentPackageConflictReason::SUMMARY_MISMATCH,
        ], $sorted);
        self::assertSame('actor_not_owner,title_mismatch,summary_mismatch', implode(',', $sorted));
        foreach ($sorted as $reason) {
            self::assertDoesNotMatchRegularExpression('/@|[0-9a-f]{8}-[0-9a-f]{4}-/i', $reason);
            self::assertDoesNotMatchRegularExpression('/[A-Z]/', $reason);
        }
    }
}

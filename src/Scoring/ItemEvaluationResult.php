<?php

declare(strict_types=1);

namespace App\Scoring;

use App\Enum\ItemScoreOutcome;
use App\Enum\ScoringMethod;

/**
 * Per-item automatic (or pending-manual) evaluation outcome.
 */
final class ItemEvaluationResult
{
    /**
     * @param numeric-string $awardedPoints
     * @param numeric-string $penaltyApplied
     */
    public function __construct(
        public readonly ItemScoreOutcome $outcome,
        public readonly ScoringMethod $scoringMethod,
        public readonly string $awardedPoints,
        public readonly string $penaltyApplied,
        public readonly bool $manualPending,
    ) {
    }
}

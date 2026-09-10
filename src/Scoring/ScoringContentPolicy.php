<?php

declare(strict_types=1);

namespace App\Scoring;

use App\Exception\AssessmentScoringException;

/**
 * Plain-text policy for assessment scoring reason codes.
 */
final class ScoringContentPolicy
{
    public function normalizeReasonCode(string $reasonCode): string
    {
        $trimmed = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $trimmed)) {
            throw AssessmentScoringException::invalidInput('reasonCode must match snake_case allowlist pattern.');
        }

        return $trimmed;
    }
}

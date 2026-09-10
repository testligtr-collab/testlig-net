<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Exception\AssessmentAttemptException;

/**
 * Plain-text policy for assessment attempt reason codes.
 */
final class AssessmentAttemptContentPolicy
{
    public function normalizeReasonCode(string $reasonCode): string
    {
        $trimmed = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $trimmed)) {
            throw AssessmentAttemptException::invalidInput('reasonCode must match snake_case allowlist pattern.');
        }

        return $trimmed;
    }

    public function normalizeCancellationReasonCode(string $cancellationReasonCode): string
    {
        $trimmed = trim($cancellationReasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $trimmed)) {
            throw AssessmentAttemptException::invalidInput(
                'cancellationReasonCode must match snake_case allowlist pattern.',
            );
        }

        return $trimmed;
    }
}

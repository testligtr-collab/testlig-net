<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Exception\AssessmentDeliveryException;

/**
 * Plain-text policy for assessment delivery overrides and reason codes.
 */
final class AssessmentDeliveryContentPolicy
{
    public const TITLE_OVERRIDE_MAX = 200;
    public const INSTRUCTIONS_OVERRIDE_MAX = 10000;

    public function normalizeOptionalTitleOverride(?string $title): ?string
    {
        return $this->normalizeOptionalPlainText($title, self::TITLE_OVERRIDE_MAX, 'titleOverride');
    }

    public function normalizeOptionalInstructionsOverride(?string $instructions): ?string
    {
        return $this->normalizeOptionalPlainText(
            $instructions,
            self::INSTRUCTIONS_OVERRIDE_MAX,
            'instructionsOverride',
        );
    }

    public function normalizeOptionalPlainText(?string $value, int $maxLength, string $field): ?string
    {
        if (null === $value) {
            return null;
        }
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return null;
        }
        if (mb_strlen($trimmed) > $maxLength) {
            throw AssessmentDeliveryException::invalidInput(\sprintf('%s exceeds maximum length.', $field));
        }
        if ($this->containsControlChars($trimmed)) {
            throw AssessmentDeliveryException::invalidInput(\sprintf('%s contains invalid characters.', $field));
        }

        return $trimmed;
    }

    public function normalizeReasonCode(string $reasonCode): string
    {
        $trimmed = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $trimmed)) {
            throw AssessmentDeliveryException::invalidInput('reasonCode must match snake_case allowlist pattern.');
        }

        return $trimmed;
    }

    public function normalizeCancellationReasonCode(string $cancellationReasonCode): string
    {
        $trimmed = trim($cancellationReasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $trimmed)) {
            throw AssessmentDeliveryException::invalidInput(
                'cancellationReasonCode must match snake_case allowlist pattern.',
            );
        }

        return $trimmed;
    }

    public function normalizeRevocationReasonCode(string $revocationReasonCode): string
    {
        $trimmed = trim($revocationReasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $trimmed)) {
            throw AssessmentDeliveryException::invalidInput(
                'revocationReasonCode must match snake_case allowlist pattern.',
            );
        }

        return $trimmed;
    }

    private function containsControlChars(string $value): bool
    {
        return 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value);
    }
}

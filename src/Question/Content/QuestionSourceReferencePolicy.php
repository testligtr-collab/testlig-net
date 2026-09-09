<?php

declare(strict_types=1);

namespace App\Question\Content;

use App\Exception\QuestionException;

/**
 * Validates optional opaque source references (not URLs / paths).
 */
final class QuestionSourceReferencePolicy
{
    public const MAX_LENGTH = 128;

    private const PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/';

    public function assertValid(?string $sourceReference): void
    {
        if (null === $sourceReference) {
            return;
        }

        if (\strlen($sourceReference) > self::MAX_LENGTH) {
            throw QuestionException::invalidInput('sourceReference must be at most 128 characters.');
        }

        if (
            str_contains($sourceReference, '://')
            || str_contains($sourceReference, '\\')
            || 1 === preg_match('/\s/', $sourceReference)
        ) {
            throw QuestionException::invalidInput('sourceReference must not contain URLs, backslashes, or whitespace.');
        }

        if (1 !== preg_match(self::PATTERN, $sourceReference)) {
            throw QuestionException::invalidInput('sourceReference format is invalid.');
        }
    }

    public function isValid(?string $sourceReference): bool
    {
        try {
            $this->assertValid($sourceReference);

            return true;
        } catch (QuestionException) {
            return false;
        }
    }
}

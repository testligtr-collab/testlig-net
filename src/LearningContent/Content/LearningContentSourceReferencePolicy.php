<?php

declare(strict_types=1);

namespace App\LearningContent\Content;

use App\Exception\LearningContentException;

/**
 * Opaque source reference policy (no URLs / secrets).
 */
final class LearningContentSourceReferencePolicy
{
    public function assertValid(?string $sourceReference): void
    {
        if (null === $sourceReference) {
            return;
        }
        if (1 !== preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/', $sourceReference)) {
            throw LearningContentException::invalidInput('sourceReference format is invalid.');
        }
    }
}

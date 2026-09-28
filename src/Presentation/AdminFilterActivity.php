<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Detects an active GET filter without treating an empty form as active.
 */
final class AdminFilterActivity
{
    public function isActive(mixed $filters): bool
    {
        if (!\is_array($filters)) {
            return false;
        }

        foreach ($filters as $value) {
            if (\is_array($value)) {
                if ([] !== $value) {
                    return true;
                }
                continue;
            }
            if (null !== $value && '' !== $value) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\LearningContent;

use App\Exception\LearningContentPackageException;

/**
 * Fail-closed package directory allowlist. Add a later approved package here.
 */
final class LearningContentPackageAllowlist
{
    public function resolve(string $relativeDirectory): LearningContentPackageTarget
    {
        $normalized = str_replace('\\', '/', trim($relativeDirectory));
        $normalized = trim($normalized, '/');
        if (str_contains($normalized, '..') || str_contains($normalized, ':')) {
            throw LearningContentPackageException::notAllowlisted();
        }

        foreach (LearningContentPackageTarget::all() as $target) {
            if ($normalized === $target->directory) {
                return $target;
            }
        }

        throw LearningContentPackageException::notAllowlisted();
    }
}

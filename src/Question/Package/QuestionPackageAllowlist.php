<?php

declare(strict_types=1);

namespace App\Question\Package;

use App\Exception\QuestionPackageException;

/**
 * Fail-closed question-package directory allowlist.
 */
final class QuestionPackageAllowlist
{
    public function resolve(string $relativeDirectory): QuestionPackageTarget
    {
        $normalized = str_replace('\\', '/', trim($relativeDirectory));
        $normalized = trim($normalized, '/');
        if (str_contains($normalized, '..') || str_contains($normalized, ':')) {
            throw QuestionPackageException::notAllowlisted();
        }

        foreach (QuestionPackageTarget::all() as $target) {
            if ($normalized === $target->directory) {
                return $target;
            }
        }

        throw QuestionPackageException::notAllowlisted();
    }
}

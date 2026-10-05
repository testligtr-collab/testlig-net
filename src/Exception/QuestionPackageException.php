<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Closed question-package import failure.
 *
 * Messages stay generic: no email, identifier, token, stem, or answer key.
 */
final class QuestionPackageException extends \RuntimeException
{
    public static function rejected(): self
    {
        return new self('Package was rejected.');
    }

    public static function notAllowlisted(): self
    {
        return new self('Package is not on the allowlist.');
    }

    public static function actorUnavailable(): self
    {
        return new self('Actor is not available.');
    }

    public static function fingerprintRequired(): self
    {
        return new self('Apply requires the plan fingerprint.');
    }

    public static function stalePlan(): self
    {
        return new self('Plan fingerprint is stale.');
    }

    public static function conflict(): self
    {
        return new self('Question package import conflict.');
    }
}

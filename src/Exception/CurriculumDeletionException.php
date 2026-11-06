<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Safe-delete refusal. The message is the only text shown to the operator.
 */
final class CurriculumDeletionException extends \RuntimeException
{
    public function __construct(string $publicMessage)
    {
        parent::__construct($publicMessage);
    }
}

<?php

declare(strict_types=1);

namespace App\Question\Import;

/**
 * Safe, already-Turkish import failure. The message must not include file text.
 */
final class QuestionCsvImportException extends \RuntimeException
{
    public const FILE = 'file';
    public const PLAN = 'plan';
    public const EXPIRED = 'expired';
    public const CONSUMED = 'consumed';
    public const STALE = 'stale';
    public const CONFLICT = 'conflict';
    public const CONFIRMATION = 'confirmation';

    public function __construct(
        public readonly string $kind,
        string $message,
    ) {
        parent::__construct($message);
    }
}

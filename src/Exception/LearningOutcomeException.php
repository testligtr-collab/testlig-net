<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\LearningOutcomeFailureReason;

final class LearningOutcomeException extends \RuntimeException
{
    private function __construct(
        private readonly LearningOutcomeFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): LearningOutcomeFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(LearningOutcomeFailureReason::Unauthorized, 'Learning outcome operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid learning outcome input.'): self
    {
        return new self(LearningOutcomeFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(LearningOutcomeFailureReason::InvalidTransition, 'Learning outcome status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(LearningOutcomeFailureReason::Conflict, 'Learning outcome operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(LearningOutcomeFailureReason::NotFound, 'Learning outcome was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(LearningOutcomeFailureReason::NotFound, 'User was not found.');
    }

    public static function programNotDraft(): self
    {
        return new self(LearningOutcomeFailureReason::ProgramNotDraft, 'Learning outcome mutations require a draft program.');
    }

    public static function programImmutable(): self
    {
        return new self(LearningOutcomeFailureReason::ProgramImmutable, 'Published curriculum structure is immutable.');
    }

    public static function crossHierarchy(): self
    {
        return new self(LearningOutcomeFailureReason::CrossHierarchy, 'Learning outcome topic must belong to the given curriculum program.');
    }
}

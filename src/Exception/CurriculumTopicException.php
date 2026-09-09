<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\CurriculumTopicFailureReason;

final class CurriculumTopicException extends \RuntimeException
{
    private function __construct(
        private readonly CurriculumTopicFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): CurriculumTopicFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(CurriculumTopicFailureReason::Unauthorized, 'Curriculum topic operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid curriculum topic input.'): self
    {
        return new self(CurriculumTopicFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(CurriculumTopicFailureReason::InvalidTransition, 'Curriculum topic status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(CurriculumTopicFailureReason::Conflict, 'Curriculum topic operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(CurriculumTopicFailureReason::NotFound, 'Curriculum topic was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(CurriculumTopicFailureReason::NotFound, 'User was not found.');
    }

    public static function programNotDraft(): self
    {
        return new self(CurriculumTopicFailureReason::ProgramNotDraft, 'Curriculum topic mutations require a draft program.');
    }

    public static function programImmutable(): self
    {
        return new self(CurriculumTopicFailureReason::ProgramImmutable, 'Published curriculum structure is immutable.');
    }

    public static function depthExceeded(): self
    {
        return new self(CurriculumTopicFailureReason::DepthExceeded, 'Curriculum topic depth cannot exceed two levels.');
    }

    public static function activeChildren(): self
    {
        return new self(CurriculumTopicFailureReason::ActiveChildren, 'Cannot archive a topic that still has active children.');
    }

    public static function crossUnit(): self
    {
        return new self(CurriculumTopicFailureReason::CrossUnit, 'Curriculum topic parent must belong to the same unit.');
    }
}

<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\LearningContentFailureReason;

final class LearningContentException extends \RuntimeException
{
    private function __construct(
        private readonly LearningContentFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): LearningContentFailureReason
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self(LearningContentFailureReason::Unauthorized, 'Learning content operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid learning content input.'): self
    {
        return new self(LearningContentFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(LearningContentFailureReason::InvalidTransition, 'Learning content status transition is not allowed.');
    }

    public static function conflict(): self
    {
        return new self(LearningContentFailureReason::Conflict, 'Learning content operation conflict.');
    }

    public static function notFound(): self
    {
        return new self(LearningContentFailureReason::NotFound, 'Learning content was not found.');
    }

    public static function userNotFound(): self
    {
        return new self(LearningContentFailureReason::NotFound, 'User was not found.');
    }

    public static function reviewSeparation(): self
    {
        return new self(LearningContentFailureReason::ReviewSeparation, 'Publisher must differ from revision author.');
    }

    public static function scopeMismatch(): self
    {
        return new self(LearningContentFailureReason::ScopeMismatch, 'Learning content scope and institution are inconsistent.');
    }

    public static function alignmentInvalid(string $detail = 'Learning content alignment is invalid.'): self
    {
        return new self(LearningContentFailureReason::AlignmentInvalid, $detail);
    }

    public static function curriculumNotPublished(): self
    {
        return new self(LearningContentFailureReason::CurriculumNotPublished, 'Publishing requires a published curriculum program.');
    }

    public static function contentInvalid(string $detail = 'Learning content structured content is invalid.'): self
    {
        return new self(LearningContentFailureReason::ContentInvalid, $detail);
    }

    public static function immutable(): self
    {
        return new self(LearningContentFailureReason::Immutable, 'Learning content revision is immutable.');
    }

    public static function assetInvalid(string $detail = 'Stored media asset is invalid.'): self
    {
        return new self(LearningContentFailureReason::AssetInvalid, $detail);
    }

    public static function revisionSealed(): self
    {
        return new self(LearningContentFailureReason::RevisionSealed, 'Sealed learning content revision cannot be modified.');
    }

    public static function revisionNotSealed(): self
    {
        return new self(LearningContentFailureReason::RevisionNotSealed, 'Learning content revision must be sealed.');
    }
}

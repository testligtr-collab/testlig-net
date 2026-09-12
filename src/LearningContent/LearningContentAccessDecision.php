<?php

declare(strict_types=1);

namespace App\LearningContent;

use App\Enum\LearningContentAccessDecisionReason;

/**
 * Typed access decision for learning content delivery (fail-closed).
 */
final readonly class LearningContentAccessDecision
{
    private function __construct(
        public bool $allowed,
        public LearningContentAccessDecisionReason $reason,
        public ?string $contentId,
        public ?string $revisionId,
        public ?int $revisionNumber,
    ) {
    }

    public static function allowed(
        string $contentId,
        string $revisionId,
        int $revisionNumber,
    ): self {
        return new self(
            allowed: true,
            reason: LearningContentAccessDecisionReason::Allowed,
            contentId: $contentId,
            revisionId: $revisionId,
            revisionNumber: $revisionNumber,
        );
    }

    public static function denied(
        LearningContentAccessDecisionReason $reason,
        ?string $contentId = null,
        ?string $revisionId = null,
        ?int $revisionNumber = null,
    ): self {
        return new self(
            allowed: false,
            reason: $reason,
            contentId: $contentId,
            revisionId: $revisionId,
            revisionNumber: $revisionNumber,
        );
    }
}

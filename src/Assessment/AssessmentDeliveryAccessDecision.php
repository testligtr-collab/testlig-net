<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Enum\AssessmentDeliveryAccessReason;

/**
 * Typed student access decision for assessment delivery (no attempt entity yet).
 */
final readonly class AssessmentDeliveryAccessDecision
{
    private function __construct(
        public bool $eligibleForAttemptCreation,
        public AssessmentDeliveryAccessReason $reason,
        public ?string $deliveryId,
        public ?string $assessmentPublicationId,
        public ?int $publicationNumber,
        public ?int $configuredMaxAttempts,
        public ?\DateTimeImmutable $opensAt,
        public ?\DateTimeImmutable $closesAt,
        public bool $attemptQuotaMustBeChecked,
    ) {
    }

    public static function allowed(
        string $deliveryId,
        string $assessmentPublicationId,
        int $publicationNumber,
        int $configuredMaxAttempts,
        \DateTimeImmutable $opensAt,
        \DateTimeImmutable $closesAt,
    ): self {
        return new self(
            eligibleForAttemptCreation: true,
            reason: AssessmentDeliveryAccessReason::Allowed,
            deliveryId: $deliveryId,
            assessmentPublicationId: $assessmentPublicationId,
            publicationNumber: $publicationNumber,
            configuredMaxAttempts: $configuredMaxAttempts,
            opensAt: $opensAt,
            closesAt: $closesAt,
            attemptQuotaMustBeChecked: true,
        );
    }

    public static function denied(
        AssessmentDeliveryAccessReason $reason,
        ?string $deliveryId = null,
        ?string $assessmentPublicationId = null,
        ?int $publicationNumber = null,
        ?int $configuredMaxAttempts = null,
        ?\DateTimeImmutable $opensAt = null,
        ?\DateTimeImmutable $closesAt = null,
    ): self {
        return new self(
            eligibleForAttemptCreation: false,
            reason: $reason,
            deliveryId: $deliveryId,
            assessmentPublicationId: $assessmentPublicationId,
            publicationNumber: $publicationNumber,
            configuredMaxAttempts: $configuredMaxAttempts,
            opensAt: $opensAt,
            closesAt: $closesAt,
            attemptQuotaMustBeChecked: false,
        );
    }
}

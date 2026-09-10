<?php

declare(strict_types=1);

namespace App\Security\Authorization;

use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentDeliveryStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only authorization projection for an assessment delivery.
 */
final readonly class AssessmentDeliveryAuthorizationSnapshot
{
    public function __construct(
        public Uuid $id,
        public Uuid $institutionId,
        public Uuid $assessmentId,
        public Uuid $assessmentPublicationId,
        public AssessmentDeliveryAudienceType $audienceType,
        public ?Uuid $classroomId,
        public ?Uuid $studentMembershipId,
        public ?Uuid $studentUserId,
        public AssessmentDeliveryStatus $status,
        public Uuid $createdById,
    ) {
    }

    public function isDraft(): bool
    {
        return AssessmentDeliveryStatus::Draft === $this->status;
    }

    public function isActive(): bool
    {
        return AssessmentDeliveryStatus::Active === $this->status;
    }
}

<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\AssessmentDeliveryFailureReason;

final class AssessmentDeliveryException extends \RuntimeException
{
    private function __construct(
        private readonly AssessmentDeliveryFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): AssessmentDeliveryFailureReason
    {
        return $this->reason;
    }

    public static function notFound(): self
    {
        return new self(AssessmentDeliveryFailureReason::NotFound, 'Assessment delivery was not found.');
    }

    public static function unauthorized(): self
    {
        return new self(AssessmentDeliveryFailureReason::Unauthorized, 'Assessment delivery operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid assessment delivery input.'): self
    {
        return new self(AssessmentDeliveryFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::InvalidTransition,
            'Assessment delivery status transition is not allowed.',
        );
    }

    public static function conflict(): self
    {
        return new self(AssessmentDeliveryFailureReason::Conflict, 'Assessment delivery operation conflict.');
    }

    public static function noEligibleRecipients(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::NoEligibleRecipients,
            'Assessment delivery has no eligible recipients.',
        );
    }

    public static function deliveryNotActive(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::DeliveryNotActive,
            'Assessment delivery is not active.',
        );
    }

    public static function notOpenYet(): self
    {
        return new self(AssessmentDeliveryFailureReason::NotOpenYet, 'Assessment delivery is not open yet.');
    }

    public static function expired(): self
    {
        return new self(AssessmentDeliveryFailureReason::Expired, 'Assessment delivery has expired.');
    }

    public static function recipientNotFound(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::RecipientNotFound,
            'Assessment delivery recipient was not found.',
        );
    }

    public static function recipientRevoked(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::RecipientRevoked,
            'Assessment delivery recipient is revoked.',
        );
    }

    public static function institutionInactive(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::InstitutionInactive,
            'Institution is not active for assessment delivery.',
        );
    }

    public static function userInactive(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::UserInactive,
            'User is not active for assessment delivery.',
        );
    }

    public static function emailNotVerified(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::EmailNotVerified,
            'User email is not verified for assessment delivery.',
        );
    }

    public static function membershipInactive(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::MembershipInactive,
            'Institution membership is not active for assessment delivery.',
        );
    }

    public static function membershipNotStudent(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::MembershipNotStudent,
            'Institution membership is not a student role.',
        );
    }

    public static function publicationIntegrityFailed(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::PublicationIntegrityFailed,
            'Assessment publication integrity check failed.',
        );
    }

    public static function publicationInvalid(string $detail = 'Assessment publication is invalid for delivery.'): self
    {
        return new self(AssessmentDeliveryFailureReason::PublicationInvalid, $detail);
    }

    public static function immutable(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::Immutable,
            'Assessment delivery or recipient identity is immutable.',
        );
    }

    public static function scopeMismatch(): self
    {
        return new self(
            AssessmentDeliveryFailureReason::ScopeMismatch,
            'Assessment delivery scope and institution are inconsistent.',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\AssessmentAttemptFailureReason;

final class AssessmentAttemptException extends \RuntimeException
{
    private function __construct(
        private readonly AssessmentAttemptFailureReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getReason(): AssessmentAttemptFailureReason
    {
        return $this->reason;
    }

    public static function notFound(): self
    {
        return new self(AssessmentAttemptFailureReason::NotFound, 'Assessment attempt was not found.');
    }

    public static function unauthorized(): self
    {
        return new self(AssessmentAttemptFailureReason::Unauthorized, 'Assessment attempt operation is not authorized.');
    }

    public static function invalidInput(string $detail = 'Invalid assessment attempt input.'): self
    {
        return new self(AssessmentAttemptFailureReason::InvalidInput, $detail);
    }

    public static function invalidTransition(): self
    {
        return new self(
            AssessmentAttemptFailureReason::InvalidTransition,
            'Assessment attempt status transition is not allowed.',
        );
    }

    public static function conflict(): self
    {
        return new self(AssessmentAttemptFailureReason::Conflict, 'Assessment attempt operation conflict.');
    }

    public static function attemptQuotaExceeded(): self
    {
        return new self(
            AssessmentAttemptFailureReason::AttemptQuotaExceeded,
            'Assessment attempt quota has been exceeded.',
        );
    }

    public static function activeAttemptExists(): self
    {
        return new self(
            AssessmentAttemptFailureReason::ActiveAttemptExists,
            'An in-progress assessment attempt already exists for this recipient.',
        );
    }

    public static function attemptNotInProgress(): self
    {
        return new self(
            AssessmentAttemptFailureReason::AttemptNotInProgress,
            'Assessment attempt is not in progress.',
        );
    }

    public static function attemptExpired(): self
    {
        return new self(AssessmentAttemptFailureReason::AttemptExpired, 'Assessment attempt has expired.');
    }

    public static function attemptTerminal(): self
    {
        return new self(
            AssessmentAttemptFailureReason::AttemptTerminal,
            'Assessment attempt is terminal and cannot be mutated.',
        );
    }

    public static function deliveryNotActive(): self
    {
        return new self(
            AssessmentAttemptFailureReason::DeliveryNotActive,
            'Assessment delivery is not active.',
        );
    }

    public static function notOpenYet(): self
    {
        return new self(AssessmentAttemptFailureReason::NotOpenYet, 'Assessment delivery is not open yet.');
    }

    public static function deliveryWindowClosed(): self
    {
        return new self(
            AssessmentAttemptFailureReason::DeliveryWindowClosed,
            'Assessment delivery window is closed.',
        );
    }

    public static function recipientNotFound(): self
    {
        return new self(
            AssessmentAttemptFailureReason::RecipientNotFound,
            'Assessment delivery recipient was not found.',
        );
    }

    public static function recipientRevoked(): self
    {
        return new self(
            AssessmentAttemptFailureReason::RecipientRevoked,
            'Assessment delivery recipient is revoked.',
        );
    }

    public static function institutionInactive(): self
    {
        return new self(
            AssessmentAttemptFailureReason::InstitutionInactive,
            'Institution is not active for assessment attempts.',
        );
    }

    public static function userInactive(): self
    {
        return new self(AssessmentAttemptFailureReason::UserInactive, 'User is not active.');
    }

    public static function emailNotVerified(): self
    {
        return new self(AssessmentAttemptFailureReason::EmailNotVerified, 'User email is not verified.');
    }

    public static function membershipInactive(): self
    {
        return new self(
            AssessmentAttemptFailureReason::MembershipInactive,
            'Institution membership is not active.',
        );
    }

    public static function membershipNotStudent(): self
    {
        return new self(
            AssessmentAttemptFailureReason::MembershipNotStudent,
            'Institution membership is not a student role.',
        );
    }

    public static function publicationIntegrityFailed(): self
    {
        return new self(
            AssessmentAttemptFailureReason::PublicationIntegrityFailed,
            'Assessment publication integrity verification failed.',
        );
    }

    public static function publicationInvalid(): self
    {
        return new self(
            AssessmentAttemptFailureReason::PublicationInvalid,
            'Assessment publication is invalid for attempt creation.',
        );
    }

    public static function answerInvalid(string $detail = 'Answer payload is invalid.'): self
    {
        return new self(AssessmentAttemptFailureReason::AnswerInvalid, $detail);
    }

    public static function answerIntegrityFailed(): self
    {
        return new self(
            AssessmentAttemptFailureReason::AnswerIntegrityFailed,
            'Encrypted answer integrity verification failed.',
        );
    }

    public static function staleAnswerVersion(): self
    {
        return new self(
            AssessmentAttemptFailureReason::StaleAnswerVersion,
            'Answer version is stale.',
        );
    }

    public static function itemNotFound(): self
    {
        return new self(AssessmentAttemptFailureReason::ItemNotFound, 'Assessment attempt item was not found.');
    }

    public static function immutable(): self
    {
        return new self(AssessmentAttemptFailureReason::Immutable, 'Assessment attempt data is immutable.');
    }

    public static function scopeMismatch(): self
    {
        return new self(AssessmentAttemptFailureReason::ScopeMismatch, 'Assessment attempt scope mismatch.');
    }

    public static function insufficientRemainingTime(): self
    {
        return new self(
            AssessmentAttemptFailureReason::InsufficientRemainingTime,
            'Insufficient remaining time to start an assessment attempt.',
        );
    }

    public static function encryptionMisconfigured(): self
    {
        return new self(
            AssessmentAttemptFailureReason::EncryptionMisconfigured,
            'Attempt answer encryption is misconfigured.',
        );
    }
}

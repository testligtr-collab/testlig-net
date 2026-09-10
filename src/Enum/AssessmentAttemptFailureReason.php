<?php

declare(strict_types=1);

namespace App\Enum;

enum AssessmentAttemptFailureReason: string
{
    case NotFound = 'not_found';
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case Conflict = 'conflict';
    case AttemptQuotaExceeded = 'attempt_quota_exceeded';
    case ActiveAttemptExists = 'active_attempt_exists';
    case AttemptNotInProgress = 'attempt_not_in_progress';
    case AttemptExpired = 'attempt_expired';
    case AttemptTerminal = 'attempt_terminal';
    case DeliveryNotActive = 'delivery_not_active';
    case NotOpenYet = 'not_open_yet';
    case DeliveryWindowClosed = 'delivery_window_closed';
    case RecipientNotFound = 'recipient_not_found';
    case RecipientRevoked = 'recipient_revoked';
    case InstitutionInactive = 'institution_inactive';
    case UserInactive = 'user_inactive';
    case EmailNotVerified = 'email_not_verified';
    case MembershipInactive = 'membership_inactive';
    case MembershipNotStudent = 'membership_not_student';
    case PublicationIntegrityFailed = 'publication_integrity_failed';
    case PublicationInvalid = 'publication_invalid';
    case AnswerInvalid = 'answer_invalid';
    case AnswerIntegrityFailed = 'answer_integrity_failed';
    case StaleAnswerVersion = 'stale_answer_version';
    case ItemNotFound = 'item_not_found';
    case Immutable = 'immutable';
    case ScopeMismatch = 'scope_mismatch';
    case InsufficientRemainingTime = 'insufficient_remaining_time';
    case EncryptionMisconfigured = 'encryption_misconfigured';
}

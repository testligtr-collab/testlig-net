<?php

declare(strict_types=1);

namespace App\Enum;

enum AssessmentDeliveryFailureReason: string
{
    case NotFound = 'not_found';
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case Conflict = 'conflict';
    case NoEligibleRecipients = 'no_eligible_recipients';
    case DeliveryNotActive = 'delivery_not_active';
    case NotOpenYet = 'not_open_yet';
    case Expired = 'expired';
    case RecipientNotFound = 'recipient_not_found';
    case RecipientRevoked = 'recipient_revoked';
    case InstitutionInactive = 'institution_inactive';
    case UserInactive = 'user_inactive';
    case EmailNotVerified = 'email_not_verified';
    case MembershipInactive = 'membership_inactive';
    case MembershipNotStudent = 'membership_not_student';
    case PublicationIntegrityFailed = 'publication_integrity_failed';
    case PublicationInvalid = 'publication_invalid';
    case Immutable = 'immutable';
    case ScopeMismatch = 'scope_mismatch';
}

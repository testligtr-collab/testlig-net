<?php

declare(strict_types=1);

namespace App\Enum;

enum AssessmentDeliveryAccessReason: string
{
    case Allowed = 'allowed';
    case DeliveryNotFound = 'delivery_not_found';
    case RecipientNotFound = 'recipient_not_found';
    case RecipientRevoked = 'recipient_revoked';
    case DeliveryNotActive = 'delivery_not_active';
    case NotOpenYet = 'not_open_yet';
    case Expired = 'expired';
    case InstitutionInactive = 'institution_inactive';
    case UserInactive = 'user_inactive';
    case EmailNotVerified = 'email_not_verified';
    case MembershipInactive = 'membership_inactive';
    case MembershipNotStudent = 'membership_not_student';
    case PublicationIntegrityFailed = 'publication_integrity_failed';
    case Conflict = 'conflict';
}

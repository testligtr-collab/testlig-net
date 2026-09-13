<?php

declare(strict_types=1);

namespace App\Enum;

enum AccessEntitlementFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case Conflict = 'conflict';
    case NotFound = 'not_found';
    case ScopeMismatch = 'scope_mismatch';
    case Immutable = 'immutable';
    case SeatLimitExceeded = 'seat_limit_exceeded';
    case HashMismatch = 'hash_mismatch';
    case GrantInvalid = 'grant_invalid';
    case PackageRetired = 'package_retired';
    case ResourceNotPublished = 'resource_not_published';
}

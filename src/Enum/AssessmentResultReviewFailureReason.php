<?php

declare(strict_types=1);

namespace App\Enum;

enum AssessmentResultReviewFailureReason: string
{
    case NotFound = 'not_found';
    case Unauthorized = 'unauthorized';
    case ScopeMismatch = 'scope_mismatch';
    case ResultNotReleased = 'result_not_released';
    case ResultWithdrawn = 'result_withdrawn';
    case ReviewPolicyNotActive = 'review_policy_not_active';
    case ReviewNotAvailable = 'review_not_available';
    case DeliveryNotSafelyClosed = 'delivery_not_safely_closed';
    case ItemReviewNotAllowed = 'item_review_not_allowed';
    case StudentAnswerNotAllowed = 'student_answer_not_allowed';
    case CorrectAnswerNotAllowed = 'correct_answer_not_allowed';
    case ExplanationNotAllowed = 'explanation_not_allowed';
    case PolicyIntegrityFailed = 'policy_integrity_failed';
    case AnswerIntegrityFailed = 'answer_integrity_failed';
    case AnswerDecryptionFailed = 'answer_decryption_failed';
    case InvalidTransition = 'invalid_transition';
    case InvalidInput = 'invalid_input';
    case Conflict = 'conflict';
}

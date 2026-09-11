<?php

declare(strict_types=1);

namespace App\Enum;

enum AssessmentScoringFailureReason: string
{
    case NotFound = 'not_found';
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case Conflict = 'conflict';
    case Immutable = 'immutable';
    case ScopeMismatch = 'scope_mismatch';
    case AttemptNotScorable = 'attempt_not_scorable';
    case ScoringInProgress = 'scoring_in_progress';
    case AnswerIntegrityFailed = 'answer_integrity_failed';
    case AnswerDecryptionFailed = 'answer_decryption_failed';
    case AnswerKeyIntegrityFailed = 'answer_key_integrity_failed';
    case PublicationIntegrityFailed = 'publication_integrity_failed';
    case ScoringFailed = 'scoring_failed';
    case ManualGradeNotAllowed = 'manual_grade_not_allowed';
    case ReleaseNotAllowed = 'release_not_allowed';
    case ResultNotReleased = 'result_not_released';
    case ItemNotFound = 'item_not_found';
}

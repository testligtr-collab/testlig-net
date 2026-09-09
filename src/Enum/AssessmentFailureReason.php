<?php

declare(strict_types=1);

namespace App\Enum;

enum AssessmentFailureReason: string
{
    case NotFound = 'not_found';
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case Immutable = 'immutable';
    case Conflict = 'conflict';
    case ScopeMismatch = 'scope_mismatch';
    case EmptyAssessment = 'empty_assessment';
    case EmptySection = 'empty_section';
    case DuplicateQuestion = 'duplicate_question';
    case QuestionNotPublished = 'question_not_published';
    case QuestionScopeMismatch = 'question_scope_mismatch';
    case QuestionRevisionMismatch = 'question_revision_mismatch';
    case GradeMismatch = 'grade_mismatch';
    case InvalidPoints = 'invalid_points';
    case ReviewSeparation = 'review_separation';
    case RevisionNotSealed = 'revision_not_sealed';
    case PublicationInvalid = 'publication_invalid';
    case AnswerIntegrityFailed = 'answer_integrity_failed';
}

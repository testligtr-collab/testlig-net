<?php

declare(strict_types=1);

namespace App\Enum;

enum LearningContentFailureReason: string
{
    case Unauthorized = 'unauthorized';
    case InvalidInput = 'invalid_input';
    case InvalidTransition = 'invalid_transition';
    case Conflict = 'conflict';
    case NotFound = 'not_found';
    case ReviewSeparation = 'review_separation';
    case ScopeMismatch = 'scope_mismatch';
    case AlignmentInvalid = 'alignment_invalid';
    case CurriculumNotPublished = 'curriculum_not_published';
    case ContentInvalid = 'content_invalid';
    case Immutable = 'immutable';
    case AssetInvalid = 'asset_invalid';
    case RevisionSealed = 'revision_sealed';
    case RevisionNotSealed = 'revision_not_sealed';
}

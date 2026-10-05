<?php

declare(strict_types=1);

namespace App\Question\Package;

final class QuestionPackageConflictReason
{
    public const ACTOR_NOT_AVAILABLE = 'actor_not_available';
    public const ACTOR_NOT_AUTHORIZED = 'actor_not_authorized';
    public const PACKAGE_NOT_ALLOWED = 'package_not_allowed';
    public const FIXTURE_CHECKSUM_MISMATCH = 'fixture_checksum_mismatch';
    public const CSV_INVALID = 'csv_invalid';
    public const SUBJECT_NOT_FOUND = 'subject_not_found';
    public const SUBJECT_INACTIVE = 'subject_inactive';
    public const OUTCOME_NOT_FOUND = 'outcome_not_found';
    public const OUTCOME_MISMATCH = 'outcome_mismatch';
    public const DUPLICATE_CODE_IN_PACKAGE = 'duplicate_code_in_package';
    public const EXISTING_OWNER_MISMATCH = 'existing_owner_mismatch';
    public const EXISTING_STATUS_MISMATCH = 'existing_status_mismatch';
    public const EXISTING_SUBJECT_MISMATCH = 'existing_subject_mismatch';
    public const EXISTING_GRADE_MISMATCH = 'existing_grade_mismatch';
    public const EXISTING_OUTCOME_MISMATCH = 'existing_outcome_mismatch';
    public const EXISTING_REVISION_MISMATCH = 'existing_revision_mismatch';
    public const EXISTING_OPTIONS_MISMATCH = 'existing_options_mismatch';
    public const EXISTING_ANSWER_KEY_MISMATCH = 'existing_answer_key_mismatch';
    public const EXISTING_EXPLANATION_MISMATCH = 'existing_explanation_mismatch';
    public const PLAN_STALE = 'plan_stale';
    public const UNEXPECTED_EXISTING_STATE = 'unexpected_existing_state';
}

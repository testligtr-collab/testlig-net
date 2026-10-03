<?php

declare(strict_types=1);

namespace App\Service\LearningContent;

/**
 * Closed, log-safe conflict codes for package import verify/dry-run.
 * Codes never include emails, UUIDs, titles, or document text.
 */
final class LearningContentPackageConflictReason
{
    public const ACTOR_NOT_OWNER = 'actor_not_owner';
    public const CONTENT_NOT_DRAFT = 'content_not_draft';
    public const REVISION_NOT_DRAFT = 'revision_not_draft';
    public const REVISION_NOT_CURRENT = 'revision_not_current';
    public const REVISION_NOT_PLACEHOLDER = 'revision_not_placeholder';
    public const SUBJECT_MISMATCH = 'subject_mismatch';
    public const GRADE_MISMATCH = 'grade_mismatch';
    public const OUTCOME_MISMATCH = 'outcome_mismatch';
    public const STABLE_CODE_MISMATCH = 'stable_code_mismatch';
    public const CONTENT_TYPE_MISMATCH = 'content_type_mismatch';
    public const TITLE_MISMATCH = 'title_mismatch';
    public const SUMMARY_MISMATCH = 'summary_mismatch';
    public const PLACEMENT_EXISTS = 'placement_exists';
    public const REVIEW_OR_PUBLISH_HISTORY_EXISTS = 'review_or_publish_history_exists';
    public const PACKAGE_ALREADY_APPLIED = 'package_already_applied';
    public const UNSUPPORTED_EXISTING_STATE = 'unsupported_existing_state';

    /**
     * @var list<string>
     */
    public const ORDER = [
        self::ACTOR_NOT_OWNER,
        self::CONTENT_NOT_DRAFT,
        self::REVISION_NOT_DRAFT,
        self::REVISION_NOT_CURRENT,
        self::REVISION_NOT_PLACEHOLDER,
        self::SUBJECT_MISMATCH,
        self::GRADE_MISMATCH,
        self::OUTCOME_MISMATCH,
        self::STABLE_CODE_MISMATCH,
        self::CONTENT_TYPE_MISMATCH,
        self::TITLE_MISMATCH,
        self::SUMMARY_MISMATCH,
        self::PLACEMENT_EXISTS,
        self::REVIEW_OR_PUBLISH_HISTORY_EXISTS,
        self::PACKAGE_ALREADY_APPLIED,
        self::UNSUPPORTED_EXISTING_STATE,
    ];

    /**
     * @param list<string> $reasons
     *
     * @return list<string>
     */
    public static function uniqueSorted(array $reasons): array
    {
        $allow = array_flip(self::ORDER);
        $unique = [];
        foreach ($reasons as $reason) {
            if (!isset($allow[$reason]) || isset($unique[$reason])) {
                continue;
            }
            $unique[$reason] = $reason;
        }
        $sorted = array_values($unique);
        usort($sorted, static fn (string $left, string $right): int => $allow[$left] <=> $allow[$right]);

        return $sorted;
    }
}

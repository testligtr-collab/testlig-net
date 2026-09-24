<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\SecurityAuditMetadataException;

/**
 * Strict allowlist metadata sanitizer for security audit events.
 */
final class SecurityAuditMetadataSanitizer
{
    /**
     * @var list<string>
     */
    private const ALLOWED_KEYS = [
        'reason',
        'source',
        'previous_roles',
        'new_roles',
        'previous_status',
        'new_status',
        'bootstrap',
        'institution_type',
        'institution_id',
        'membership_role',
        'previous_membership_role',
        'new_membership_role',
        'reason_code',
        'academic_year_id',
        'classroom_id',
        'membership_id',
        'source_classroom_id',
        'target_classroom_id',
        'old_status',
        'old_role',
        'new_role',
        'grade_level',
        'subject_id',
        'curriculum_id',
        'source_curriculum_id',
        'unit_id',
        'topic_id',
        'curriculum_unit_id',
        'curriculum_topic_id',
        'parent_topic_id',
        'classroom_course_id',
        'course_teacher_assignment_id',
        'version',
        'weekly_lesson_hours',
        'estimated_minutes',
        'position',
        'code',
        'old_curriculum_id',
        'new_curriculum_id',
        'question_id',
        'revision_id',
        'revision_number',
        'learning_outcome_id',
        'curriculum_program_id',
        'assessment_id',
        'assessment_revision_id',
        'publication_id',
        'publication_number',
        'assessment_type',
        'section_count',
        'item_count',
        'delivery_id',
        'audience_type',
        'recipient_id',
        'recipient_count',
        'assessment_publication_id',
        'attempt_id',
        'attempt_number',
        'attempt_item_id',
        'answer_version',
        'answered_item_count',
        'unanswered_required_count',
        'scoring_run_id',
        'result_release_id',
        'run_number',
        'release_number',
        'scoring_version',
        'correct_count',
        'incorrect_count',
        'unanswered_count',
        'manual_pending_count',
        'status',
        'policy_id',
        'policy_version',
        'availability_mode',
        'analytics_type',
        'cohort_size',
        'suppressed',
        'release_id',
        'content_id',
        'catalog_topic_id',
        'catalog_topic_lesson_id',
        'catalog_subject_id',
        'canonical_subject_id',
        'previous_canonical_subject_id',
        'asset_id',
        'outcome_id',
        'asset_kind',
        'scan_status',
        'schema_version',
        'alignment_count',
        'asset_count',
        'package_id',
        'package_version',
        'package_version_id',
        'license_id',
        'seat_id',
        'resource_type',
        'resource_id',
        'source_type',
        'decision_reason',
        'grant_source',
        'seat_count',
        'seat_limit',
        'access_class',
        'target_type',
        'licensee_type',
        'external_reference',
        'version_number',
        'policy_hash',
        'expires_at',
        'grant_kind',
        // Stage 2.17 commerce / payment: identifiers, integer minor-unit amounts, and
        // digests only. Never raw idempotency keys, card data, provider secrets, or PII.
        'offer_id',
        'offer_code',
        'offer_hash',
        'order_id',
        'order_hash',
        'order_item_id',
        'order_item_count',
        'purchaser_type',
        'billing_type',
        'billing_interval',
        'currency',
        'quantity',
        'tax_rate_basis_points',
        'amount_minor',
        'subtotal_amount_minor',
        'discount_amount_minor',
        'tax_amount_minor',
        'grand_total_amount_minor',
        'refund_amount_minor',
        'payment_attempt_id',
        'payment_event_id',
        'provider_code',
        'provider_environment',
        'event_type',
        'event_hash',
        'sequence_number',
        'failure_code',
        'refund_id',
        'refund_number',
        'subscription_id',
        'subscription_hash',
        'subscriber_type',
        'period_key',
        'period_number',
        'period_start',
        'period_end',
        'fulfillment_id',
        'fulfillment_number',
        'cancellation_reason_code',
        'reversal_reason_code',
        // Stage 2.18 webhook inbox / checkout orchestration (identifiers only).
        'inbox_event_id',
        'processing_status',
        'attempt_count',
        'last_failure_reason_code',
        // Stage 2.19 payment operations / reconciliation (safe aggregates only).
        'environment',
        'reconciliation_run_id',
        'reconciliation_outcome',
        'checked_count',
        'matched_count',
        'discrepancy_count',
        'failed_count',
        'selected_count',
        'processed_count',
        'rejected_count',
        'retry_pending_count',
        'dead_letter_count',
        'skipped_count',
        // Stage 2.22.2b phone verification claim manager (identifiers / counts only).
        'claim_id',
        'purpose',
        'failed_attempt_count',
        'revoked_claim_count',
        // Stage 2.22.3 onboarding applications (identifiers / status only).
        'application_id',
        'application_kind',
        'account_type',
        'registration_flow',
        // Stage 2.22.5b parent–student link consent (identifiers / flags only).
        'link_id',
        'invitation_id',
        'grants_child_data_access',
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN_NORMALIZED = [
        'password',
        'plainpassword',
        'plain_password',
        'token',
        'secrettoken',
        'secret',
        'authorization',
        'cookie',
        'session',
        'sessionid',
        'jwt',
        'apikey',
        'api_key',
        'email',
        'ip',
        'ipaddress',
        'useragent',
        'user_agent',
        'databaseurl',
        'database_url',
        'requestbody',
        'request_body',
        'form',
        'formcontent',
        'contenthash',
        'content_hash',
        'answerintegrity',
        'answer_integrity',
        'answerpayload',
        'answer_payload',
        'correctstablekey',
        'correct_stable_key',
        'stem',
        'explanation',
        'title',
        'body',
        'summary',
        'storagekey',
        'storage_key',
        'originalfilename',
        'original_filename',
        'url',
        'tokens',
        'ciphertext',
        'nonce',
        'encryption_key',
        'encryptionkey',
        'plaintext',
        'selectedstablekey',
        'selected_stable_key',
        'selectedstablekeys',
        'selected_stable_keys',
        'answerciphertext',
        'answer_ciphertext',
        'answernonce',
        'answer_nonce',
        'otp',
        'plainotp',
        'plain_otp',
        'plaincode',
        'plain_code',
        'invitationcode',
        'invitation_code',
        'codedigest',
        'code_digest',
        'pepper',
        'phone',
        'normalizedphone',
        'normalized_phone',
        'targetphone',
        'target_phone',
        'targetnormalizedphone',
        'target_normalized_phone',
    ];

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return array<string, bool|float|int|string|list<bool|float|int|string>|null>
     */
    public function sanitize(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (!\is_string($key) || '' === $key) {
                throw SecurityAuditMetadataException::forbiddenKey((string) $key);
            }

            $normalizedKey = $this->normalizeKey($key);
            if ($this->isForbidden($normalizedKey)) {
                throw SecurityAuditMetadataException::forbiddenKey($key);
            }

            if (!\in_array($normalizedKey, self::ALLOWED_KEYS, true)) {
                throw SecurityAuditMetadataException::forbiddenKey($key);
            }

            $clean[$normalizedKey] = $this->normalizeValue($normalizedKey, $value);
        }

        return $clean;
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));

        return str_replace(['-', ' '], '_', $key);
    }

    private function isForbidden(string $normalizedKey): bool
    {
        $compact = str_replace('_', '', $normalizedKey);
        foreach (self::FORBIDDEN_NORMALIZED as $forbidden) {
            $forbiddenCompact = str_replace('_', '', $forbidden);
            if ($normalizedKey === $forbidden || $compact === $forbiddenCompact) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool|float|int|string|list<bool|float|int|string>|null
     */
    private function normalizeValue(string $key, mixed $value): mixed
    {
        if (null === $value || \is_bool($value) || \is_int($value) || \is_float($value) || \is_string($value)) {
            return $value;
        }

        if (\is_array($value)) {
            /** @var list<bool|float|int|string> $list */
            $list = [];
            foreach ($value as $item) {
                if (\is_bool($item) || \is_int($item) || \is_float($item) || \is_string($item)) {
                    $list[] = $item;
                    continue;
                }
                throw SecurityAuditMetadataException::unsupportedValue($key);
            }

            return $list;
        }

        throw SecurityAuditMetadataException::unsupportedValue($key);
    }
}

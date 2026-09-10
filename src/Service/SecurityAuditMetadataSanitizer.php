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

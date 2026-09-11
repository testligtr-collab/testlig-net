<?php

declare(strict_types=1);

namespace App\ResultReview;

use App\Enum\ResultReviewAvailabilityMode;
use App\Exception\AssessmentResultReviewException;
use App\Question\Content\QuestionContentCanonicalEncoder;
use App\Time\UtcInstant;

/**
 * SHA-256 over canonical review-policy content (not encryption).
 */
final class AssessmentResultReviewPolicyHasher
{
    public const HASH_HEX_LENGTH = 64;

    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly QuestionContentCanonicalEncoder $encoder,
    ) {
    }

    public function hash(
        int $schemaVersion,
        ResultReviewAvailabilityMode $availabilityMode,
        ?\DateTimeImmutable $scheduledAt,
        bool $showScoreSummary,
        bool $showItemOutcomes,
        bool $showStudentAnswer,
        bool $showCorrectAnswer,
        bool $showExplanation,
    ): string {
        return hash('sha256', $this->encoder->encode($this->canonicalPayload(
            $schemaVersion,
            $availabilityMode,
            $scheduledAt,
            $showScoreSummary,
            $showItemOutcomes,
            $showStudentAnswer,
            $showCorrectAnswer,
            $showExplanation,
        )));
    }

    public function verify(
        string $storedHash,
        int $schemaVersion,
        ResultReviewAvailabilityMode $availabilityMode,
        ?\DateTimeImmutable $scheduledAt,
        bool $showScoreSummary,
        bool $showItemOutcomes,
        bool $showStudentAnswer,
        bool $showCorrectAnswer,
        bool $showExplanation,
    ): void {
        if ('' === $storedHash || 1 !== preg_match('/^[0-9a-f]{'.self::HASH_HEX_LENGTH.'}$/', $storedHash)) {
            throw AssessmentResultReviewException::policyIntegrityFailed();
        }

        $expected = $this->hash(
            $schemaVersion,
            $availabilityMode,
            $scheduledAt,
            $showScoreSummary,
            $showItemOutcomes,
            $showStudentAnswer,
            $showCorrectAnswer,
            $showExplanation,
        );
        if (!hash_equals($expected, $storedHash)) {
            throw AssessmentResultReviewException::policyIntegrityFailed();
        }
    }

    /**
     * @return array{
     *     schemaVersion: int,
     *     availabilityMode: string,
     *     scheduledAt: ?string,
     *     showScoreSummary: bool,
     *     showItemOutcomes: bool,
     *     showStudentAnswer: bool,
     *     showCorrectAnswer: bool,
     *     showExplanation: bool
     * }
     */
    private function canonicalPayload(
        int $schemaVersion,
        ResultReviewAvailabilityMode $availabilityMode,
        ?\DateTimeImmutable $scheduledAt,
        bool $showScoreSummary,
        bool $showItemOutcomes,
        bool $showStudentAnswer,
        bool $showCorrectAnswer,
        bool $showExplanation,
    ): array {
        $scheduledCanonical = null;
        if (null !== $scheduledAt) {
            $scheduledCanonical = UtcInstant::ensure($scheduledAt)->format('Y-m-d H:i:s');
        }

        return [
            'schemaVersion' => $schemaVersion,
            'availabilityMode' => $availabilityMode->value,
            'scheduledAt' => $scheduledCanonical,
            'showScoreSummary' => $showScoreSummary,
            'showItemOutcomes' => $showItemOutcomes,
            'showStudentAnswer' => $showStudentAnswer,
            'showCorrectAnswer' => $showCorrectAnswer,
            'showExplanation' => $showExplanation,
        ];
    }
}

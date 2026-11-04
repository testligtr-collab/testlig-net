<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Entity\HomeworkDefinition;
use App\Entity\HomeworkEvaluation;
use App\Entity\HomeworkSubmission;
use App\Enum\HomeworkEvaluationLabel;

/**
 * Plain-text rules for homework fields. Length is Unicode code points (mb_strlen UTF-8),
 * matching the browser counter. HTML and control characters are rejected, not stripped.
 */
final class HomeworkText
{
    public const TITLE_MIN = 1;
    public const TITLE_MAX = HomeworkDefinition::TITLE_MAX;
    public const DESCRIPTION_MIN = 1;
    public const DESCRIPTION_MAX = HomeworkDefinition::DESCRIPTION_MAX;
    public const BODY_MIN = 1;
    public const BODY_MAX = HomeworkSubmission::BODY_MAX;
    public const FEEDBACK_MAX = HomeworkEvaluation::FEEDBACK_MAX;

    /**
     * @return string trimmed, valid title
     *
     * @throws \InvalidArgumentException with a Turkish message safe to show to the teacher
     */
    public static function title(string $value): string
    {
        return self::plain($value, self::TITLE_MIN, self::TITLE_MAX, false, 'Ödev başlığı');
    }

    /**
     * @throws \InvalidArgumentException with a Turkish message safe to show to the teacher
     */
    public static function description(string $value): string
    {
        return self::plain($value, self::DESCRIPTION_MIN, self::DESCRIPTION_MAX, true, 'Ödev açıklaması');
    }

    /**
     * Student draft body: empty string allowed after trim; whitespace-only becomes ''.
     *
     * @throws \InvalidArgumentException with a Turkish message safe to show to the student
     */
    public static function draftBody(string $value): string
    {
        return self::plain($value, 0, self::BODY_MAX, true, 'Yanıtın');
    }

    /**
     * Student submitted body: trim after normalize; empty rejected.
     *
     * @throws \InvalidArgumentException with a Turkish message safe to show to the student
     */
    public static function submittedBody(string $value): string
    {
        return self::plain($value, self::BODY_MIN, self::BODY_MAX, true, 'Yanıtın');
    }

    /**
     * Teacher feedback. Empty string becomes null. needs_rework requires non-empty after trim.
     *
     * @return string|null normalized feedback or null when empty and allowed
     *
     * @throws \InvalidArgumentException with a Turkish message safe to show to the teacher
     */
    public static function feedback(string $value, HomeworkEvaluationLabel $label): ?string
    {
        $min = HomeworkEvaluationLabel::NeedsRework === $label ? 1 : 0;
        $normalized = self::plain($value, $min, self::FEEDBACK_MAX, true, 'Geri bildirim');
        if ('' === $normalized) {
            return null;
        }

        return $normalized;
    }

    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    private static function plain(string $value, int $min, int $max, bool $multiline, string $label): string
    {
        if (1 !== preg_match('//u', $value)) {
            throw new \InvalidArgumentException($label.' geçersiz karakter içeriyor.');
        }
        if ($multiline) {
            // Browsers submit CRLF; textarea.value in JS counts LF. Count and store LF only.
            $value = str_replace(["\r\n", "\r"], "\n", $value);
        }
        $value = trim($value);
        $length = self::length($value);
        if ($length < $min) {
            throw new \InvalidArgumentException($label.' boş olamaz.');
        }
        if ($length > $max) {
            throw new \InvalidArgumentException(\sprintf('%s en fazla %s karakter olabilir.', $label, number_format($max, 0, ',', '.')));
        }
        if (str_contains($value, '<') || str_contains($value, '>')) {
            throw new \InvalidArgumentException($label.' HTML içeremez.');
        }
        $pattern = $multiline
            ? '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/'
            : '/[\\x00-\\x1F\\x7F]/';
        if (1 === preg_match($pattern, $value)) {
            throw new \InvalidArgumentException($label.' geçersiz karakter içeriyor.');
        }

        return $value;
    }
}

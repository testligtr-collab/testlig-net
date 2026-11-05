<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\HomeworkEvaluationLabel;
use App\Service\Homework\HomeworkText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HomeworkTextTest extends TestCase
{
    public function testTurkishCharactersAreCountedAsCodePointsNotBytes(): void
    {
        $title = str_repeat('ğ', 150);
        self::assertSame(300, \strlen($title));
        self::assertSame(150, HomeworkText::length($title));
        self::assertSame($title, HomeworkText::title($title));

        $this->expectException(\InvalidArgumentException::class);
        HomeworkText::title(str_repeat('ğ', 151));
    }

    public function testEmojiIsOneCodePointAtTheTitleBoundary(): void
    {
        $emoji = "\u{1F600}";
        self::assertSame(4, \strlen($emoji));
        $title = str_repeat($emoji, 150);
        self::assertSame(150, HomeworkText::length($title));
        self::assertSame($title, HomeworkText::title($title));

        $this->expectException(\InvalidArgumentException::class);
        HomeworkText::title($title.$emoji);
    }

    public function testDescriptionBoundaryIsTenThousandCodePoints(): void
    {
        $emoji = "\u{1F4DA}";
        $max = str_repeat($emoji, 10000);
        self::assertSame($max, HomeworkText::description($max));

        $this->expectException(\InvalidArgumentException::class);
        HomeworkText::description($max.'a');
    }

    public function testLengthIsMeasuredAfterTrim(): void
    {
        self::assertSame('abc', HomeworkText::title("  abc \t"));
        self::assertSame(str_repeat('a', 150), HomeworkText::title('  '.str_repeat('a', 150).'  '));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyValues(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'tabs and newlines' => ["\t\n \r"];
    }

    #[DataProvider('emptyValues')]
    public function testBlankTitleAndDescriptionAreRejected(string $value): void
    {
        foreach ([HomeworkText::title(...), HomeworkText::description(...)] as $validate) {
            try {
                $validate($value);
                self::fail('Blank text must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('boş', $exception->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeValues(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'angle bracket' => ['a > b'];
        yield 'bell control' => ["abc\x07"];
        yield 'nul byte' => ["abc\x00def"];
        yield 'delete control' => ["abc\x7F"];
        yield 'invalid utf8' => ["abc\xC3\x28"];
    }

    #[DataProvider('unsafeValues')]
    public function testHtmlControlAndInvalidUtf8AreRejected(string $value): void
    {
        foreach ([HomeworkText::title(...), HomeworkText::description(...)] as $validate) {
            try {
                $validate($value);
                self::fail('Unsafe text must be rejected, not stripped.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTitleRejectsLineBreaksButDescriptionKeepsThem(): void
    {
        try {
            HomeworkText::title("ilk\nikinci");
            self::fail('Title is single line.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame("birinci\nikinci\n\nüçüncü", HomeworkText::description("birinci\r\nikinci\r\n\r\nüçüncü"));
    }

    public function testCrlfCountsAsOneCharacterLikeTheBrowser(): void
    {
        $value = str_repeat('a', 9998)."\r\n".'b';
        self::assertSame(10000, HomeworkText::length(str_replace(["\r\n", "\r"], "\n", $value)));
        self::assertSame(10000, HomeworkText::length(HomeworkText::description($value)));
    }

    public function testBodyLimitMatchesTheSubmissionEntity(): void
    {
        self::assertSame(\App\Entity\HomeworkSubmission::BODY_MAX, HomeworkText::BODY_MAX);
        self::assertSame(20000, HomeworkText::BODY_MAX);
        self::assertSame(1, HomeworkText::BODY_MIN);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['    '];
        yield 'tabs and newlines' => ["\t\r\n \n"];
    }

    #[DataProvider('blankBodies')]
    public function testDraftBodyAllowsBlankAndNormalizesItToEmpty(string $value): void
    {
        self::assertSame('', HomeworkText::draftBody($value));
    }

    #[DataProvider('blankBodies')]
    public function testSubmittedBodyRejectsBlank(string $value): void
    {
        try {
            HomeworkText::submittedBody($value);
            self::fail('A blank submitted body must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Yanıtın boş olamaz.', $exception->getMessage());
        }
    }

    public function testBodyKeepsLineBreaksTabsAndStoresLfOnly(): void
    {
        $value = "  birinci satır\r\n\tgirintili ikinci\r\rüçüncü  ";
        $expected = "birinci satır\n\tgirintili ikinci\n\nüçüncü";

        self::assertSame($expected, HomeworkText::draftBody($value));
        self::assertSame($expected, HomeworkText::submittedBody($value));
    }

    public function testBodyLimitIsTwentyThousandCodePointsForDraftAndSubmit(): void
    {
        $emoji = "\u{1F600}";
        $max = str_repeat($emoji, HomeworkText::BODY_MAX);
        self::assertSame(HomeworkText::BODY_MAX * 4, \strlen($max));
        self::assertSame(HomeworkText::BODY_MAX, HomeworkText::length($max));
        self::assertSame($max, HomeworkText::draftBody($max));
        self::assertSame($max, HomeworkText::submittedBody($max));

        foreach ([HomeworkText::draftBody(...), HomeworkText::submittedBody(...)] as $validate) {
            try {
                $validate($max.'a');
                self::fail('One code point over the limit must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Yanıtın en fazla 20.000 karakter olabilir.', $exception->getMessage());
            }
        }
    }

    public function testBodyLengthIsMeasuredAfterTrimAndCrlfNormalization(): void
    {
        $value = '  '.str_repeat('ğ', HomeworkText::BODY_MAX - 2)."\r\n".'ş  ';

        $draft = HomeworkText::draftBody($value);
        self::assertSame(HomeworkText::BODY_MAX, HomeworkText::length($draft));
        self::assertSame($draft, HomeworkText::submittedBody($value));
    }

    #[DataProvider('unsafeValues')]
    public function testBodyRejectsHtmlControlAndInvalidUtf8ForDraftAndSubmit(string $value): void
    {
        foreach ([HomeworkText::draftBody(...), HomeworkText::submittedBody(...)] as $validate) {
            try {
                $validate($value);
                self::fail('Unsafe body must be rejected, not stripped.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringStartsWith('Yanıtın ', $exception->getMessage());
            }
        }
    }

    public function testBodyHtmlMessageIsSafeToShowToTheStudent(): void
    {
        foreach ([HomeworkText::draftBody(...), HomeworkText::submittedBody(...)] as $validate) {
            try {
                $validate('<img src=x onerror=alert(1)>');
                self::fail('HTML must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Yanıtın HTML içeremez.', $exception->getMessage());
            }
        }
    }

    public function testFeedbackLimitMatchesTheEvaluationEntity(): void
    {
        self::assertSame(\App\Entity\HomeworkEvaluation::FEEDBACK_MAX, HomeworkText::FEEDBACK_MAX);
        self::assertSame(5000, HomeworkText::FEEDBACK_MAX);
    }

    /**
     * @return iterable<string, array{HomeworkEvaluationLabel}>
     */
    public static function evaluationLabels(): iterable
    {
        yield 'reviewed' => [HomeworkEvaluationLabel::Reviewed];
        yield 'needs_rework' => [HomeworkEvaluationLabel::NeedsRework];
    }

    #[DataProvider('blankBodies')]
    public function testFeedbackIsOptionalForReviewedAndBlankBecomesNull(string $value): void
    {
        self::assertNull(HomeworkText::feedback($value, HomeworkEvaluationLabel::Reviewed));
    }

    #[DataProvider('blankBodies')]
    public function testFeedbackIsRequiredForNeedsReworkAfterTrim(string $value): void
    {
        try {
            HomeworkText::feedback($value, HomeworkEvaluationLabel::NeedsRework);
            self::fail('needs_rework needs non-empty feedback.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Geri bildirim boş olamaz.', $exception->getMessage());
        }
    }

    #[DataProvider('evaluationLabels')]
    public function testFeedbackKeepsLineBreaksAndStoresLfOnly(HomeworkEvaluationLabel $label): void
    {
        $value = "  Aferin\r\n\tikinci satır\r\rüçüncü ğüşiöç  ";

        self::assertSame("Aferin\n\tikinci satır\n\nüçüncü ğüşiöç", HomeworkText::feedback($value, $label));
    }

    #[DataProvider('evaluationLabels')]
    public function testFeedbackLimitIsFiveThousandCodePointsNotBytes(HomeworkEvaluationLabel $label): void
    {
        $emoji = "\u{1F600}";
        $max = str_repeat($emoji, HomeworkText::FEEDBACK_MAX);
        self::assertSame(HomeworkText::FEEDBACK_MAX * 4, \strlen($max));
        self::assertSame(HomeworkText::FEEDBACK_MAX, HomeworkText::length($max));
        self::assertSame($max, HomeworkText::feedback($max, $label));

        $turkish = str_repeat('ğ', HomeworkText::FEEDBACK_MAX);
        self::assertSame($turkish, HomeworkText::feedback($turkish, $label));

        try {
            HomeworkText::feedback($max.'a', $label);
            self::fail('One code point over the limit must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Geri bildirim en fazla 5.000 karakter olabilir.', $exception->getMessage());
        }
    }

    #[DataProvider('evaluationLabels')]
    public function testFeedbackLengthIsMeasuredAfterTrimAndCrlfNormalization(HomeworkEvaluationLabel $label): void
    {
        $value = '  '.str_repeat('ş', HomeworkText::FEEDBACK_MAX - 2)."\r\n".'ç  ';

        $feedback = HomeworkText::feedback($value, $label);
        self::assertNotNull($feedback);
        self::assertSame(HomeworkText::FEEDBACK_MAX, HomeworkText::length($feedback));
    }

    #[DataProvider('unsafeValues')]
    public function testFeedbackRejectsHtmlControlAndInvalidUtf8ForBothLabels(string $value): void
    {
        foreach ([HomeworkEvaluationLabel::Reviewed, HomeworkEvaluationLabel::NeedsRework] as $label) {
            try {
                HomeworkText::feedback($value, $label);
                self::fail('Unsafe feedback must be rejected, not stripped.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringStartsWith('Geri bildirim ', $exception->getMessage());
            }
        }
    }

    public function testFeedbackHtmlMessageIsSafeToShowToTheTeacher(): void
    {
        foreach ([HomeworkEvaluationLabel::Reviewed, HomeworkEvaluationLabel::NeedsRework] as $label) {
            try {
                HomeworkText::feedback('<img src=x onerror=alert(1)>', $label);
                self::fail('HTML must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Geri bildirim HTML içeremez.', $exception->getMessage());
            }
        }
    }
}

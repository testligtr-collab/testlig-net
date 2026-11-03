<?php

declare(strict_types=1);

namespace App\Tests\Service;

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
}

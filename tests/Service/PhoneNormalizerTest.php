<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\PhoneNormalizationException;
use App\Service\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNormalizerTest extends TestCase
{
    private PhoneNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new PhoneNormalizer();
    }

    #[DataProvider('acceptedProvider')]
    public function testAcceptsTurkeyMobileForms(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($input));
        $pair = $this->normalizer->normalizePair($input);
        self::assertSame($expected, $pair['normalizedPhone']);
        self::assertSame($expected, $pair['phone']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function acceptedProvider(): iterable
    {
        yield 'e164' => ['+905321234567', '+905321234567'];
        yield 'national_with_0' => ['05321234567', '+905321234567'];
        yield 'national_without_0' => ['5321234567', '+905321234567'];
        yield 'international_00' => ['00905321234567', '+905321234567'];
        yield 'spaces_dashes' => ['+90 532 123 45 67', '+905321234567'];
        yield 'parens' => ['(0532) 123-45-67', '+905321234567'];
    }

    #[DataProvider('rejectedProvider')]
    public function testRejectsInvalidNumbers(string $input): void
    {
        try {
            $this->normalizer->normalize($input);
            self::fail('Expected PhoneNormalizationException');
        } catch (PhoneNormalizationException $exception) {
            self::assertStringNotContainsStringIgnoringCase(preg_replace('/\D+/', '', $input) ?: 'x', $exception->getMessage());
            self::assertSame('Geçerli bir Türkiye cep telefonu numarası girin.', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedProvider(): iterable
    {
        yield 'landline_istanbul' => ['02121234567'];
        yield 'landline_e164' => ['+902121234567'];
        yield 'us_number' => ['+14155552671'];
        yield 'too_short' => ['053212345'];
        yield 'too_long' => ['053212345678'];
        yield 'empty' => ['   '];
        yield 'letters' => ['05ab1234567'];
        yield 'not_mobile_prefix' => ['02165551212'];
        yield 'fullwidth_digits' => ['０５３２１２３４５６７'];
        yield 'unicode_homoglyph' => ['+90۵۳۲۱۲۳۴۵۶۷'];
        yield 'mixed_letters_digits' => ['05۳۲1234567'];
        yield 'null_byte' => ["05321234567\0"];
    }
}

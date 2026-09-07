<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EmailNormalizer;
use PHPUnit\Framework\TestCase;

final class EmailNormalizerTest extends TestCase
{
    private EmailNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new EmailNormalizer();
    }

    public function testTrimsAndLowercasesEmail(): void
    {
        $pair = $this->normalizer->normalizePair('  Ali.Veli@Example.COM  ');

        self::assertSame('Ali.Veli@Example.COM', $pair['email']);
        self::assertSame('ali.veli@example.com', $pair['normalizedEmail']);
        self::assertSame('ali.veli@example.com', $this->normalizer->normalize('  Ali.Veli@Example.COM  '));
    }

    public function testRejectsEmptyEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalizer->normalize('   ');
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalizer->normalize('not-an-email');
    }
}

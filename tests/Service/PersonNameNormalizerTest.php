<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PersonNameNormalizer;
use PHPUnit\Framework\TestCase;

final class PersonNameNormalizerTest extends TestCase
{
    private PersonNameNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new PersonNameNormalizer();
    }

    public function testTrimsAndCollapsesWhitespace(): void
    {
        self::assertSame('Ayşe Yılmaz', $this->normalizer->normalize('  Ayşe   Yılmaz  ', 'firstName'));
    }

    public function testPreservesTurkishCharacters(): void
    {
        self::assertSame('Ğüşıöç', $this->normalizer->normalize('Ğüşıöç', 'firstName'));
        self::assertSame('İstanbul', $this->normalizer->normalize('İstanbul', 'lastName'));
    }

    public function testRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->normalizer->normalize('   ', 'firstName');
    }
}

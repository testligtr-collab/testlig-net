<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ParentLinkCodeCodec;
use PHPUnit\Framework\TestCase;

final class ParentLinkCodeCodecTest extends TestCase
{
    public function testCodesAreReadableAndUnambiguous(): void
    {
        $codec = new ParentLinkCodeCodec();
        $seen = [];
        for ($i = 0; $i < 200; ++$i) {
            $raw = $codec->generate();
            self::assertSame(ParentLinkCodeCodec::LENGTH, \strlen($raw));
            self::assertSame(1, preg_match('/^['.ParentLinkCodeCodec::ALPHABET.']+$/', $raw));
            self::assertSame(0, preg_match('/[01IO]/', $raw));
            $seen[$raw] = true;
            self::assertSame($codec->display($raw), implode('-', str_split($raw, 4)));
        }
        self::assertCount(200, $seen);
    }

    public function testNormalizeIsCaseInsensitiveAndStripsSeparators(): void
    {
        $codec = new ParentLinkCodeCodec();
        $raw = 'ABCD2345EFGH';
        self::assertSame($raw, $codec->normalize(' abcd-2345-efgh '));
        self::assertSame($raw, $codec->normalize('abcd 2345 efgh'));
        self::assertNull($codec->normalize('ABCD-2345-EFG0'));
        self::assertNull($codec->normalize('short'));
    }
}

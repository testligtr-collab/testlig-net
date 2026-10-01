<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PublicSitemapDocument;
use PHPUnit\Framework\TestCase;

final class PublicSitemapDocumentTest extends TestCase
{
    public function testXmlEscapesLocAndLastmod(): void
    {
        $xml = PublicSitemapDocument::render([
            ['loc' => 'https://testlig.net/dersler/1/a&b', 'lastmod' => '2026-09-30<script>'],
        ]);

        self::assertStringContainsString('https://testlig.net/dersler/1/a&amp;b', $xml);
        self::assertStringContainsString('2026-09-30&lt;script&gt;', $xml);
        self::assertStringNotContainsString('<script>', $xml);
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
    }
}

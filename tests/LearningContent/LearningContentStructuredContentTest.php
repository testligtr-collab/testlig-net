<?php

declare(strict_types=1);

namespace App\Tests\LearningContent;

use App\Enum\LearningContentSourceType;
use App\Exception\LearningContentException;
use App\LearningContent\Content\LearningContentCanonicalEncoder;
use App\LearningContent\Content\LearningContentDocument;
use App\LearningContent\Content\LearningContentDocumentValidator;
use App\LearningContent\Content\LearningContentHashBuilder;
use App\LearningContent\Content\LearningContentHasher;
use PHPUnit\Framework\TestCase;

final class LearningContentStructuredContentTest extends TestCase
{
    private LearningContentDocumentValidator $validator;
    private LearningContentHashBuilder $hashBuilder;
    private LearningContentHasher $hasher;

    protected function setUp(): void
    {
        $this->validator = new LearningContentDocumentValidator();
        $this->hasher = new LearningContentHasher(new LearningContentCanonicalEncoder());
        $this->hashBuilder = new LearningContentHashBuilder();
    }

    public function testAcceptsAllowlistedBlocks(): void
    {
        $doc = LearningContentDocument::fromArray([
            'schemaVersion' => 1,
            'blocks' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'Kesirler'],
                ['type' => 'paragraph', 'text' => 'Pay ve payda.'],
                ['type' => 'list', 'items' => ['Ornek 1', 'Ornek 2']],
                ['type' => 'quote', 'text' => 'Sabirla tekrar et.'],
                ['type' => 'math', 'latex' => 'a/b'],
                ['type' => 'callout', 'variant' => 'info', 'blocks' => [
                    ['type' => 'paragraph', 'text' => 'Dikkat'],
                ]],
                ['type' => 'image_reference', 'mediaId' => '018f0000-0000-7000-8000-000000000001'],
            ],
        ]);

        $this->validator->validate($doc);
        self::assertSame(1, $doc->schemaVersion);
        self::assertCount(7, $doc->blocks);
    }

    public function testRejectsHtmlInParagraph(): void
    {
        $this->expectException(LearningContentException::class);
        $this->validator->validate(LearningContentDocument::fromArray([
            'schemaVersion' => 1,
            'blocks' => [['type' => 'paragraph', 'text' => '<script>alert(1)</script>']],
        ]));
    }

    public function testRejectsExternalUrlInParagraph(): void
    {
        $this->expectException(LearningContentException::class);
        $this->validator->validate(LearningContentDocument::fromArray([
            'schemaVersion' => 1,
            'blocks' => [['type' => 'paragraph', 'text' => 'See https://evil.example']],
        ]));
    }

    public function testRejectsUnknownBlockType(): void
    {
        $this->expectException(LearningContentException::class);
        $this->validator->validate(LearningContentDocument::fromArray([
            'schemaVersion' => 1,
            'blocks' => [['type' => 'iframe', 'src' => 'https://x']],
        ]));
    }

    public function testHashChangesWhenContentTampered(): void
    {
        $docA = LearningContentDocument::paragraph('A');
        $docB = LearningContentDocument::paragraph('B');

        $payloadA = $this->hashBuilder->build(
            1,
            'tr',
            15,
            LearningContentSourceType::Original->value,
            null,
            $docA->toArray(),
            null,
        );
        $payloadB = $this->hashBuilder->build(
            1,
            'tr',
            15,
            LearningContentSourceType::Original->value,
            null,
            $docB->toArray(),
            null,
        );

        $hashA = $this->hasher->hash($payloadA);
        $hashB = $this->hasher->hash($payloadB);

        self::assertNotSame($hashA, $hashB);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hashA);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\LearningContent;

use App\Exception\LearningContentException;
use App\LearningContent\Content\LearningContentDocument;
use App\LearningContent\Content\LearningContentDocumentValidator;
use App\LearningContent\Content\LearningContentHashBuilder;
use App\LearningContent\Content\LearningContentHasher;
use App\LearningContent\Content\LearningContentCanonicalEncoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class LearningContentStructuredContentTest extends TestCase
{
    private LearningContentDocumentValidator $validator;
    private LearningContentHasher $hasher;
    private LearningContentHashBuilder $hashBuilder;

    protected function setUp(): void
    {
        $this->validator = new LearningContentDocumentValidator();
        $encoder = new LearningContentCanonicalEncoder();
        $this->hasher = new LearningContentHasher($encoder);
        $this->hashBuilder = new LearningContentHashBuilder();
    }

    public function testAllowlistAcceptsSupportedBlocks(): void
    {
        $mediaId = (new UuidV7())->toRfc4122();
        $doc = LearningContentDocument::fromArray([
            'schemaVersion' => 1,
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'Hello'],
                ['type' => 'heading', 'level' => 2, 'text' => 'Title'],
                ['type' => 'list', 'items' => ['a', 'b']],
                ['type' => 'quote', 'text' => 'Quoted'],
                ['type' => 'math', 'latex' => 'x^2'],
                ['type' => 'image_reference', 'mediaId' => $mediaId],
                ['type' => 'video_reference', 'mediaId' => $mediaId],
                ['type' => 'callout', 'variant' => 'info', 'blocks' => [
                    ['type' => 'paragraph', 'text' => 'Tip body'],
                ]],
            ],
        ]);
        $this->validator->validate($doc);
        self::assertSame(1, $doc->schemaVersion);
    }

    public function testRejectsHtmlScriptAndExternalUrls(): void
    {
        try {
            $this->validator->validate(LearningContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [['type' => 'paragraph', 'text' => '<script>alert(1)</script>']],
            ]));
            self::fail('html rejected');
        } catch (LearningContentException) {
            self::assertTrue(true);
        }

        try {
            $this->validator->validate(LearningContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [['type' => 'paragraph', 'text' => 'See https://evil.example']],
            ]));
            self::fail('url rejected');
        } catch (LearningContentException) {
            self::assertTrue(true);
        }

        try {
            $this->validator->validate(LearningContentDocument::fromArray([
                'schemaVersion' => 1,
                'blocks' => [['type' => 'iframe', 'src' => 'https://x']],
            ]));
            self::fail('iframe type rejected');
        } catch (LearningContentException) {
            self::assertTrue(true);
        }
    }

    public function testHashChangesWhenContentTampered(): void
    {
        $payload = $this->hashBuilder->build(
            1,
            'tr',
            10,
            'original',
            null,
            LearningContentDocument::paragraph('A')->toArray(),
            null,
        );
        $hashA = $this->hasher->hash($payload);
        $payload['structuredContent'] = LearningContentDocument::paragraph('B')->toArray();
        $hashB = $this->hasher->hash($payload);
        self::assertNotSame($hashA, $hashB);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hashA);
    }
}

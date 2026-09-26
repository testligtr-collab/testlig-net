<?php

declare(strict_types=1);

namespace App\Tests\LearningContent\StudentView;

use App\Dto\StudentContent\StudentContentBlockView;
use App\LearningContent\StudentView\StudentContentBlockNormalizer;
use PHPUnit\Framework\TestCase;

final class StudentContentBlockNormalizerTest extends TestCase
{
    private StudentContentBlockNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new StudentContentBlockNormalizer();
    }

    public function testNormalizesAllowedBlocksAndSkipsUnknownPayload(): void
    {
        $views = $this->normalizer->normalize([
            'schemaVersion' => 1,
            'blocks' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'Baslik'],
                ['type' => 'paragraph', 'text' => 'Paragraf'],
                ['type' => 'list', 'items' => ['A', 'B']],
                ['type' => 'list', 'style' => 'ordered', 'items' => ['1', '2']],
                ['type' => 'quote', 'text' => 'Alinti'],
                ['type' => 'math', 'latex' => 'a^2'],
                ['type' => 'callout', 'variant' => 'warning', 'blocks' => [
                    ['type' => 'paragraph', 'text' => 'Uyari'],
                ]],
                ['type' => 'evil_unknown', 'payload' => 'SECRET_UNKNOWN_PAYLOAD'],
                ['type' => 'image_reference', 'mediaId' => '11111111-1111-4111-8111-111111111111'],
                ['type' => 'heading', 'level' => 9, 'text' => 'Bad level'],
                ['type' => 'paragraph', 'text' => '<script>alert(1)</script>'],
                'not-an-array',
            ],
        ]);

        self::assertCount(7, $views);
        self::assertSame(StudentContentBlockView::TYPE_HEADING, $views[0]->type);
        self::assertSame(2, $views[0]->level);
        self::assertSame(StudentContentBlockView::LIST_UNORDERED, $views[2]->listStyle);
        self::assertSame(StudentContentBlockView::LIST_ORDERED, $views[3]->listStyle);
        self::assertSame('warning', $views[6]->variant);
        self::assertNotNull($views[6]->children);
        self::assertCount(1, $views[6]->children);

        $blob = implode(' ', array_map(
            static fn (StudentContentBlockView $v): string => ($v->text ?? '').($v->latex ?? '').implode(' ', $v->items ?? []),
            $views,
        ));
        self::assertStringNotContainsString('SECRET_UNKNOWN_PAYLOAD', $blob);
        self::assertStringNotContainsString('<script>', $blob);
        self::assertStringNotContainsString('11111111-1111-4111-8111-111111111111', $blob);
    }

    public function testMalformedDocumentYieldsEmpty(): void
    {
        self::assertSame([], $this->normalizer->normalize([]));
        self::assertSame([], $this->normalizer->normalize(['blocks' => 'x']));
    }

    public function testNestedCalloutRejected(): void
    {
        $views = $this->normalizer->normalize([
            'blocks' => [
                ['type' => 'callout', 'variant' => 'info', 'blocks' => [
                    ['type' => 'callout', 'variant' => 'tip', 'blocks' => [
                        ['type' => 'paragraph', 'text' => 'Nested'],
                    ]],
                ]],
            ],
        ]);
        self::assertSame([], $views);
    }

    public function testInvalidListStyleSkipped(): void
    {
        $views = $this->normalizer->normalize([
            'blocks' => [
                ['type' => 'list', 'style' => 'menu', 'items' => ['A']],
            ],
        ]);
        self::assertSame([], $views);
    }

    public function testVideoAndDocumentAreRenderedOnlyFromSafeFields(): void
    {
        $assetId = '018f0000-0000-7000-8000-000000000099';
        $views = $this->normalizer->normalize([
            'blocks' => [
                [
                    'type' => 'video',
                    'provider' => 'youtube',
                    'providerVideoId' => 'dQw4w9WgXcQ',
                    'title' => 'Konu videosu',
                    'description' => 'Kisa aciklama',
                    'url' => 'https://evil.example/watch',
                ],
                [
                    'type' => 'video',
                    'provider' => 'youtube',
                    'providerVideoId' => 'not-valid',
                    'title' => '',
                    'description' => '',
                ],
                [
                    'type' => 'document',
                    'assetId' => $assetId,
                    'label' => 'Calisma',
                ],
                [
                    'type' => 'document',
                    'assetId' => 'not-a-uuid',
                    'label' => 'Bozuk',
                ],
            ],
        ], static function (int $index, string $id, string $label) use ($assetId): ?StudentContentBlockView {
            if ($id !== $assetId) {
                return null;
            }

            return StudentContentBlockView::document($label, 'Notlar.pdf', '2 KB', '/ogrenci/dersler/a/b/c/adim/pdf/'.$index);
        });

        self::assertCount(2, $views);
        self::assertSame(StudentContentBlockView::TYPE_VIDEO, $views[0]->type);
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $views[0]->embedSrc);
        self::assertStringNotContainsString('evil.example', (string) $views[0]->embedSrc);
        self::assertSame(StudentContentBlockView::TYPE_DOCUMENT, $views[1]->type);
        self::assertSame('Notlar.pdf', $views[1]->fileName);
    }

    public function testReadyVideoOmitsVisitorUrl(): void
    {
        $views = $this->normalizer->normalize([
            'blocks' => [[
                'type' => 'video',
                'provider' => 'vimeo',
                'providerVideoId' => '123456789',
                'title' => '',
                'description' => '',
            ]],
        ]);

        self::assertCount(1, $views);
        self::assertSame('Video', $views[0]->text);
        self::assertSame('https://player.vimeo.com/video/123456789', $views[0]->embedSrc);
    }

    public function testDocumentClosureFailureIsSkipped(): void
    {
        $views = $this->normalizer->normalize([
            'blocks' => [[
                'type' => 'document',
                'assetId' => '018f0000-0000-7000-8000-000000000099',
                'label' => 'Calisma',
            ]],
        ], static function (): StudentContentBlockView {
            throw new \RuntimeException('missing file');
        });

        self::assertSame([], $views);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Question\Image;

use App\Exception\QuestionException;
use App\Question\Content\QuestionContentDocument;
use App\Question\Image\QuestionImagePlacement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class QuestionImagePlacementTest extends TestCase
{
    public function testClientMediaIdIsRejectedAndServerIdCanMove(): void
    {
        $placement = new QuestionImagePlacement();
        $stem = QuestionContentDocument::paragraph('Kok');
        $options = [
            ['stableKey' => 'opt_1', 'content' => QuestionContentDocument::paragraph('Bir'), 'position' => 1],
            ['stableKey' => 'opt_2', 'content' => QuestionContentDocument::paragraph('Iki'), 'position' => 2],
        ];
        $foreign = QuestionContentDocument::fromArray([
            'schemaVersion' => 1,
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'Kok'],
                ['type' => 'image_reference', 'mediaId' => '11111111-1111-4111-8111-111111111111'],
            ],
        ]);
        try {
            $placement->apply($foreign, null, $options, null, null, 'stem');
            self::fail('foreign image was accepted');
        } catch (QuestionException $exception) {
            self::assertSame('question_image_scope', $exception->getMessage());
        }

        $staged = Uuid::fromString('22222222-2222-4222-8222-222222222222');
        $placed = $placement->apply($stem, null, $options, null, $staged, 'option:2');
        $encoded = json_encode($placed['options'][1]['content']->toArray());
        self::assertIsString($encoded);
        self::assertStringContainsString($staged->toRfc4122(), $encoded);
        self::assertStringNotContainsString('image_reference', (string) json_encode($placed['stem']->toArray()));

        $kept = $placement->apply(
            QuestionContentDocument::paragraph('Yeni'),
            null,
            $options,
            ['type' => 'image_reference', 'mediaId' => $staged->toRfc4122()],
            null,
            'explanation',
        );
        self::assertIsArray($kept['explanation']);
        self::assertStringContainsString($staged->toRfc4122(), (string) json_encode($kept['explanation']));
        self::assertStringNotContainsString('image_reference', (string) json_encode($kept['stem']->toArray()));
    }
}

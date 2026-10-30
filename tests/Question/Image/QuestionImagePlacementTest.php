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

    public function testSlotsStayWithTheirOwnKeysWhenOneOptionImageIsOmitted(): void
    {
        $placement = new QuestionImagePlacement();
        $options = [
            ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('Bir'), 'position' => 1],
            ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('Iki'), 'position' => 2],
        ];
        $ids = [
            'stem' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'option:opt_a' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'option:opt_b' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'explanation' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        ];
        $blocks = [];
        foreach ($ids as $slot => $id) {
            $blocks[$slot] = ['type' => 'image_reference', 'mediaId' => $id];
        }
        $explanation = ['schemaVersion' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'Cozum']]];
        $placed = $placement->place(QuestionContentDocument::paragraph('Kok'), $explanation, $options, $blocks);
        self::assertStringContainsString($ids['stem'], (string) json_encode($placed['stem']->toArray()));
        self::assertStringContainsString($ids['option:opt_a'], (string) json_encode($placed['options'][0]['content']->toArray()));
        self::assertStringContainsString($ids['option:opt_b'], (string) json_encode($placed['options'][1]['content']->toArray()));
        self::assertStringContainsString($ids['explanation'], (string) json_encode($placed['explanation']));

        unset($blocks['option:opt_b']);
        $reordered = [
            ['stableKey' => 'opt_b', 'content' => QuestionContentDocument::paragraph('Iki'), 'position' => 1],
            ['stableKey' => 'opt_a', 'content' => QuestionContentDocument::paragraph('Bir'), 'position' => 2],
        ];
        $again = $placement->place(QuestionContentDocument::paragraph('Kok'), $explanation, $reordered, $blocks);
        self::assertSame('opt_b', $again['options'][0]['stableKey']);
        self::assertSame('opt_a', $again['options'][1]['stableKey']);
        self::assertStringNotContainsString('image_reference', (string) json_encode($again['options'][0]['content']->toArray()));
        self::assertStringContainsString($ids['option:opt_a'], (string) json_encode($again['options'][1]['content']->toArray()));
        self::assertStringNotContainsString($ids['option:opt_b'], (string) json_encode($again['options'][1]['content']->toArray()));
        self::assertStringContainsString($ids['stem'], (string) json_encode($again['stem']->toArray()));
        self::assertStringContainsString($ids['explanation'], (string) json_encode($again['explanation']));

        $foreign = QuestionContentDocument::fromArray([
            'schemaVersion' => 1,
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'Kok'],
                ['type' => 'image_reference', 'mediaId' => '11111111-1111-4111-8111-111111111111'],
            ],
        ]);
        try {
            $placement->place($foreign, null, $options, []);
            self::fail('client image was accepted');
        } catch (QuestionException $exception) {
            self::assertSame('question_image_scope', $exception->getMessage());
        }
    }
}

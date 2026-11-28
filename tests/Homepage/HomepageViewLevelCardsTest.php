<?php

declare(strict_types=1);

namespace App\Tests\Homepage;

use App\Dto\PublicCatalogGradeSummary;
use App\Enum\GradeLevel;
use App\Homepage\HomepageView;
use PHPUnit\Framework\TestCase;

final class HomepageViewLevelCardsTest extends TestCase
{
    public function testStaticPreviewHasNoCatalogLinkAndEveryLevelIsPreparing(): void
    {
        $cards = $this->cards(HomepageView::demo());

        self::assertSame(['ilkokul', 'ortaokul', 'lise', 'universite-hazirlik'], array_keys($cards));
        foreach ($cards as $id => $card) {
            self::assertNull($card['actionHref'], $id);
            self::assertSame('İçerikler hazırlanıyor', $card['status'] ?? null, $id);
            self::assertArrayNotHasKey('note', $card, $id);
        }
    }

    public function testSingleReadyGradeLinksStraightToThatGradeAndNamesIt(): void
    {
        $cards = $this->cards(HomepageView::demo([1 => new PublicCatalogGradeSummary(1, 1, 1)]));

        self::assertSame('/dersler/1', $cards['ilkokul']['actionHref']);
        self::assertSame('1. sınıf derslerini gör', $cards['ilkokul']['actionLabel']);
        self::assertSame('Şu an yayımlanmış sınıf: 1.', $cards['ilkokul']['note']);
        self::assertArrayNotHasKey('status', $cards['ilkokul']);

        foreach (['ortaokul', 'lise', 'universite-hazirlik'] as $id) {
            self::assertNull($cards[$id]['actionHref'], $id);
            self::assertSame('İçerikler hazırlanıyor', $cards[$id]['status'] ?? null, $id);
        }
    }

    public function testSeveralReadyGradesUseTheStageFilterAndOtherStagesStayIndependent(): void
    {
        $cards = $this->cards(HomepageView::demo([
            1 => new PublicCatalogGradeSummary(1, 1, 2),
            3 => new PublicCatalogGradeSummary(3, 2, 4),
            6 => new PublicCatalogGradeSummary(6, 1, 1),
            10 => new PublicCatalogGradeSummary(10, 1, 3),
        ]));

        self::assertSame('/dersler?kademe=ilkokul', $cards['ilkokul']['actionHref']);
        self::assertSame('Sınıfları keşfet', $cards['ilkokul']['actionLabel']);
        self::assertSame('Şu an yayımlanmış sınıflar: 1, 3.', $cards['ilkokul']['note']);

        self::assertSame('/dersler/6', $cards['ortaokul']['actionHref']);
        self::assertSame('6. sınıf derslerini gör', $cards['ortaokul']['actionLabel']);

        self::assertSame('/dersler/10', $cards['lise']['actionHref']);
        self::assertSame('10. sınıf derslerini gör', $cards['lise']['actionLabel']);

        // No catalog model mapping exists for TYT/AYT/YDT: never a link, whatever is published.
        self::assertNull($cards['universite-hazirlik']['actionHref']);
        self::assertSame('İçerikler hazırlanıyor', $cards['universite-hazirlik']['status'] ?? null);
    }

    public function testGradesWithoutPublishedSubjectsDoNotMakeALevelUsable(): void
    {
        $cards = $this->cards(HomepageView::demo([
            5 => new PublicCatalogGradeSummary(5, 0, 0),
            2 => new PublicCatalogGradeSummary(2, 0, 3),
        ]));

        foreach (['ilkokul', 'ortaokul', 'lise'] as $id) {
            self::assertNull($cards[$id]['actionHref'], $id);
            self::assertSame('İçerikler hazırlanıyor', $cards[$id]['status'] ?? null, $id);
        }
    }

    public function testStageGradeMappingIsTheCatalogEnumMapping(): void
    {
        self::assertSame([1, 2, 3, 4], array_map(static fn (GradeLevel $g): int => $g->value, GradeLevel::forStage('ilkokul')));
        self::assertSame([5, 6, 7, 8], array_map(static fn (GradeLevel $g): int => $g->value, GradeLevel::forStage('ortaokul')));
        self::assertSame([9, 10, 11, 12], array_map(static fn (GradeLevel $g): int => $g->value, GradeLevel::forStage('lise')));
        self::assertSame([], GradeLevel::forStage('universite-hazirlik'));
        self::assertSame([], GradeLevel::forStage(''));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function cards(HomepageView $view): array
    {
        $byId = [];
        foreach ($view->levelCards as $card) {
            $byId[$card['id']] = $card;
        }

        return $byId;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Homepage;

use App\Dto\PublicCatalogGradeSummary;
use App\Homepage\HomepageView;
use PHPUnit\Framework\TestCase;

final class HomepageViewGradeBooksTest extends TestCase
{
    public function testStaticPreviewHasEightGradesAllPreparingWithGradeOneSelected(): void
    {
        $books = HomepageView::demo()->gradeBooks;

        self::assertSame(range(1, 8), array_column($books, 'grade'));
        self::assertSame(['1', '2', '3', '4', '5', '6', '7', '8 · LGS'], array_column($books, 'label'));
        self::assertSame(array_fill(0, 8, false), array_column($books, 'ready'));
        self::assertSame([true, false, false, false, false, false, false, false], array_column($books, 'selected'));
        self::assertStringContainsString('Henüz yayımlanmış ders yok', HomepageView::demo()->gradeSelectionNote);
    }

    public function testOnlyGradesWithPublishedSubjectsAreReadyAndLowestReadyGradeIsSelected(): void
    {
        $view = HomepageView::demo([
            6 => new PublicCatalogGradeSummary(6, 2, 9),
            3 => new PublicCatalogGradeSummary(3, 0, 0),
            9 => new PublicCatalogGradeSummary(9, 1, 1),
        ]);
        $byGrade = array_column($view->gradeBooks, null, 'grade');

        self::assertSame([6], array_keys(array_filter($byGrade, static fn (array $book): bool => $book['ready'])));
        self::assertSame(2, $byGrade[6]['subjectCount']);
        self::assertSame(9, $byGrade[6]['unitCount']);
        self::assertSame([6], array_keys(array_filter($byGrade, static fn (array $book): bool => $book['selected'])));
        self::assertArrayNotHasKey(9, $byGrade);
        self::assertSame('grade-panel-6', $byGrade[6]['panelId']);
        self::assertSame('tab-6', $byGrade[6]['id']);
        self::assertStringContainsString('Yayımlanmış dersi olan sınıflar', $view->gradeSelectionNote);
        self::assertStringNotContainsString('örnek gösterim', $view->gradeSelectionNote);
    }
}

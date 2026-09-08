<?php

declare(strict_types=1);

namespace App\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Guards against regressions that previously caused mobile horizontal overflow
 * when width + padding were computed without border-box.
 */
final class PageLayoutCssContractTest extends TestCase
{
    public function testPageRuleUsesBorderBoxAndAvoidsViewportWidthUnits(): void
    {
        $cssPath = \dirname(__DIR__, 2).'/assets/styles/app.css';
        self::assertFileExists($cssPath);
        $css = file_get_contents($cssPath);
        self::assertIsString($css);

        self::assertMatchesRegularExpression(
            '/\.page\s*\{[^}]*box-sizing:\s*border-box;[^}]*\}/s',
            $css,
            '.page must set box-sizing: border-box so padding is inside width.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.page\s*\{[^}]*\b100vw\b[^}]*\}/s',
            $css,
            '.page must not use 100vw (scrollbar/gutter overflow risk).',
        );
        self::assertMatchesRegularExpression(
            '/\.page\s*\{[^}]*max-width:\s*100%;[^}]*\}/s',
            $css,
            '.page should cap at 100% of the containing block.',
        );
    }
}

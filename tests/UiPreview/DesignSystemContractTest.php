<?php

declare(strict_types=1);

namespace App\Tests\UiPreview;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

final class DesignSystemContractTest extends TestCase
{
    public function testDesignTokensAndA11yContractsExistInCss(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');

        foreach ([
            '--tl-brand:',
            '--tl-cta:',
            '--tl-font-display:',
            '--tl-space-4:',
            '--tl-radius-md:',
            '--tl-shadow-md:',
            '--tl-container:',
            ':focus-visible',
            'prefers-reduced-motion',
            '.page {',
            'max-width: 100%',
            '.bottom-nav',
            '.panel-sidebar',
            '.skip-link',
        ] as $needle) {
            self::assertStringContainsString($needle, $css, 'Missing contract: '.$needle);
        }

        self::assertStringNotContainsString('overflow-x: hidden', $css);
        self::assertStringNotContainsString('overflow-x:hidden', $css);
    }

    public function testPublicHeaderExposesAccessibilityAttributes(): void
    {
        $header = (string) file_get_contents(\dirname(__DIR__, 2).'/templates/components/public_header.html.twig');
        self::assertMatchesRegularExpression('/aria-controls="mobile-site-nav"/', $header);
        self::assertMatchesRegularExpression('/aria-expanded="false"/', $header);
        self::assertMatchesRegularExpression('/id="mobile-site-nav"[\s\S]*?\bhidden\b/', $header);
        self::assertStringContainsString('aria-current', $header);
        self::assertStringContainsString('aria-label="Ana menü"', $header);
        self::assertStringContainsString('data-action="click->mobile-nav#closeOnNavigate"', $header);
    }

    public function testMobileNavControllerHandlesEscapeResizeAndCleanup(): void
    {
        $js = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/mobile_nav_controller.js');

        self::assertStringContainsString('disconnect()', $js);
        self::assertStringContainsString("removeEventListener('keydown'", $js);
        self::assertStringContainsString("removeEventListener('resize'", $js);
        self::assertStringContainsString("addEventListener('keydown'", $js);
        self::assertStringContainsString("addEventListener('resize'", $js);
        self::assertStringContainsString("event.key === 'Escape'", $js);
        self::assertStringContainsString('restoreFocus', $js);
        self::assertStringContainsString('closeOnNavigate', $js);
        self::assertStringContainsString("const DESKTOP_MQ = '(min-width: 1024px)'", $js);
        self::assertStringContainsString('matchMedia(DESKTOP_MQ)', $js);
        self::assertStringContainsString('buttonTarget.focus()', $js);
    }

    public function testSkipLinkAndFocusVisibleRemainAvailable(): void
    {
        $base = (string) file_get_contents(\dirname(__DIR__, 2).'/templates/base.html.twig');
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');

        self::assertStringContainsString('skip-link', $base);
        self::assertStringContainsString(':focus-visible', $css);
    }

    public function testPreviewViewsLiveUnderUiPreviewNamespaceAndAreImmutableDemos(): void
    {
        $finder = (new Finder())->files()->in(\dirname(__DIR__, 2).'/src/UiPreview')->name('*PreviewView.php');
        self::assertGreaterThanOrEqual(3, iterator_count($finder));

        foreach ($finder as $file) {
            $code = $file->getContents();
            self::assertStringContainsString('namespace App\\UiPreview;', $code);
            self::assertStringContainsString('final class', $code);
            self::assertStringContainsString('public static function demo(): self', $code);
            self::assertStringNotContainsString('EntityManager', $code);
            self::assertStringNotContainsString('Connection', $code);
            self::assertStringNotContainsString('Repository', $code);
        }
    }
}

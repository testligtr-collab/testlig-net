<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class ReleaseCandidateStaticGuardTest extends TestCase
{
    public function testComingSoonLegalItemsAreNotLinks(): void
    {
        $root = \dirname(__DIR__, 2).'/templates';
        $public = (string) file_get_contents($root.'/components/public_footer.html.twig');
        $home = (string) file_get_contents($root.'/homepage/_footer.html.twig');
        $links = (string) file_get_contents($root.'/legal/_links.html.twig');
        self::assertStringContainsString("include 'legal/_links.html.twig'", $public);
        self::assertStringContainsString("include 'legal/_links.html.twig'", $home);
        self::assertStringContainsString("path('app_legal_privacy')", $links);
        self::assertStringContainsString("path('app_legal_terms')", $links);
        self::assertStringContainsString("path('app_legal_cookies')", $links);
        self::assertStringContainsString("path('app_legal_children')", $links);
        self::assertStringNotContainsString('href="#"', $public.$home.$links);
        self::assertStringNotContainsString('Gizlilik (yakında)', $public);
    }

    public function testErrorTemplatesDoNotPrintExceptionMessages(): void
    {
        foreach (['error.html.twig', 'error403.html.twig', 'error404.html.twig'] as $file) {
            $html = (string) file_get_contents(\dirname(__DIR__, 2).'/templates/bundles/TwigBundle/Exception/'.$file);
            self::assertStringNotContainsString('exception', strtolower($html));
            self::assertStringContainsString('path(\'app_home\')', $html);
            self::assertStringContainsString('path(\'app_login\')', $html);
            self::assertSame(1, preg_match_all('/<h1>/', $html));
        }
    }
}

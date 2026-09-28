<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class ReleaseCandidateStaticGuardTest extends TestCase
{
    public function testComingSoonLegalItemsAreNotLinks(): void
    {
        $html = (string) file_get_contents(\dirname(__DIR__, 2).'/templates/components/public_footer.html.twig');
        self::assertStringNotContainsString('href="#"', $html);
        self::assertStringContainsString('Gizlilik (yakında)', $html);
        self::assertStringContainsString('Kullanım koşulları (yakında)', $html);
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

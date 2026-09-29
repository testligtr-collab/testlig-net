<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use PHPUnit\Framework\TestCase;

final class PortalSurfaceGuardTest extends TestCase
{
    public function testPortalTemplatesStayFreeOfInlineMarkup(): void
    {
        $root = \dirname(__DIR__, 2);
        $files = [
            $root.'/templates/components/portal_icon.html.twig',
            $root.'/assets/styles/portal.css',
        ];
        foreach (['student', 'parent'] as $area) {
            $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/templates/'.$area));
            foreach ($directory as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $joined = '';
        foreach ($files as $file) {
            $joined .= (string) file_get_contents($file);
        }

        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=|onclick=|onerror=|onload=|javascript:|\|raw\b/i', $joined);
        self::assertStringContainsString('name not in allowed', (string) file_get_contents($root.'/templates/components/portal_icon.html.twig'));
        self::assertStringNotContainsString('styles/admin.css', (string) file_get_contents($root.'/templates/student/layout.html.twig'));
        self::assertStringNotContainsString('styles/admin.css', (string) file_get_contents($root.'/templates/parent/layout.html.twig'));
    }
}

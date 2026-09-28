<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

final class OptionalTrackerGuardTest extends TestCase
{
    public function testTemplatesAndEntryScriptDoNotAddTrackersOrConsentCookies(): void
    {
        $needles = ['gtag(', 'googletagmanager', 'google-analytics', 'facebook.net', 'doubleclick', 'consent-cookie', 'cookie-banner'];
        $files = [
            \dirname(__DIR__, 2).'/assets/app.js',
            \dirname(__DIR__, 2).'/src/Security/ContentSecurityPolicy.php',
        ];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/templates'));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.twig')) {
                $files[] = $file->getPathname();
            }
        }
        foreach ($files as $path) {
            $text = strtolower((string) file_get_contents($path));
            foreach ($needles as $needle) {
                self::assertStringNotContainsString($needle, $text, $path);
            }
        }
    }
}

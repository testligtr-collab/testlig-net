<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

final class TemplateInlineGuardTest extends TestCase
{
    public function testTemplatesDoNotCarryInlineHandlersOrScripts(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/templates'));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getPathname(), '.twig')) {
                continue;
            }
            $html = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/\son(click|submit|load|change|error)=/', $html, $file->getPathname());
            self::assertStringNotContainsString('<script', strtolower($html), $file->getPathname());
            self::assertStringNotContainsString('javascript:', strtolower($html), $file->getPathname());
            self::assertStringNotContainsString(' style=', $html, $file->getPathname());
        }
    }
}

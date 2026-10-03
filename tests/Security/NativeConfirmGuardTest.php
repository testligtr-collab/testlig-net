<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

final class NativeConfirmGuardTest extends TestCase
{
    public function testControllersAndTemplatesDoNotUseNativeConfirm(): void
    {
        $root = \dirname(__DIR__, 2);
        $paths = [
            $root.\DIRECTORY_SEPARATOR.'assets'.\DIRECTORY_SEPARATOR.'controllers',
            $root.\DIRECTORY_SEPARATOR.'assets'.\DIRECTORY_SEPARATOR.'confirm_dialog.js',
            $root.\DIRECTORY_SEPARATOR.'templates',
        ];
        foreach ($this->files($paths) as $file) {
            $body = (string) file_get_contents($file);
            self::assertStringNotContainsString('window.confirm', $body, $file);
            self::assertDoesNotMatchRegularExpression('/(?<![\w.$])confirm\s*\(/', $body, $file);
        }
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function files(array $paths): array
    {
        $found = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $found[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $name = $file->getPathname();
                if (str_ends_with($name, '.js') || str_ends_with($name, '.twig')) {
                    $found[] = $name;
                }
            }
        }

        return $found;
    }
}

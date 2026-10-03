<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use PHPUnit\Framework\TestCase;

final class BrowserAcceptanceGuardTest extends TestCase
{
    public function testFixtureIsRegisteredOnlyForTheTestContainer(): void
    {
        $services = (string) file_get_contents($this->root('config/services.yaml'));
        $marker = 'App\\Tests\\Browser\\PrepareBrowserAcceptanceCommand:';
        $whenTest = strpos($services, 'when@test:');
        $position = strpos($services, $marker);
        self::assertNotFalse($whenTest);
        self::assertNotFalse($position);
        self::assertGreaterThan($whenTest, $position);
        self::assertSame(1, substr_count($services, $marker));
        $command = (string) file_get_contents($this->root('tests/Browser/PrepareBrowserAcceptanceCommand.php'));
        self::assertStringNotContainsString('#[Route', $command);
        self::assertStringContainsString('!== $this->kernel->getEnvironment()', $command);
    }

    public function testPlaywrightDoesNotBypassCspOrCallProduction(): void
    {
        $config = (string) file_get_contents($this->root('browser/playwright.config.ts'));
        self::assertStringContainsString('retries: 0', $config);
        self::assertStringContainsString('workers: 1', $config);
        self::assertStringContainsString('fullyParallel: false', $config);
        self::assertStringContainsString("video: 'off'", $config);
        self::assertStringNotContainsString('bypassCSP: true', $config);
        self::assertDoesNotMatchRegularExpression('/https?:\/\/([a-z0-9-]+\.)?testlig\.net/i', $config);

        $suite = $this->root('browser/tests');
        $combined = '';
        foreach (scandir($suite) ?: [] as $file) {
            if (str_ends_with($file, '.ts')) {
                $combined .= (string) file_get_contents($suite.\DIRECTORY_SEPARATOR.$file);
            }
        }
        self::assertStringNotContainsString('bypassCSP', $combined);
        self::assertDoesNotMatchRegularExpression('/https?:\/\/([a-z0-9-]+\.)?testlig\.net/i', $combined);
        self::assertStringContainsString('www.youtube-nocookie.com', $combined);
        self::assertStringContainsString('player.vimeo.com', $combined);
        self::assertStringNotContainsString("page.once('dialog'", $combined);
        self::assertStringNotContainsString('dialog.accept', $combined);
        self::assertStringNotContainsString('dialog.dismiss', $combined);
        self::assertStringContainsString('`${role}-${route}-${viewport}.png`', $combined);
        self::assertDoesNotMatchRegularExpression('/screenshot\(\{[^}]*[0-9a-f]{8}-[0-9a-f]{4}-/i', $combined);
    }

    public function testDeployDoesNotPublishTheFixtureOrRunLocalSsh(): void
    {
        $deploy = (string) file_get_contents($this->root('.github/workflows/deploy.yml'));
        self::assertStringNotContainsString('app:browser-acceptance:prepare', $deploy);
        self::assertStringNotContainsString('playwright', $deploy);
        $ssh = (string) file_get_contents($this->root('deploy/vds/gha-ssh-config.sh'));
        self::assertStringContainsString('ConnectionAttempts 1', $ssh);
        $ci = (string) file_get_contents($this->root('.github/workflows/ci.yml'));
        self::assertStringContainsString('browser-acceptance', $ci);
        self::assertStringContainsString('127.0.0.1:8080', $ci);
        self::assertStringContainsString('npm audit', $ci);
    }

    private function root(string $path): string
    {
        return \dirname(__DIR__, 2).\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $path);
    }
}

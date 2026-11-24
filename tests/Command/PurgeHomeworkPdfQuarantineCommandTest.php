<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Homework\Pdf\HomeworkPdfStoragePaths;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeHomeworkPdfQuarantineCommandTest extends KernelTestCase
{
    /** @var list<string> */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_link($file) || is_file($file)) {
                @unlink($file);
            }
        }
        $this->createdFiles = [];
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testPurgeCommandDryRunLiveAndInvalidAge(): void
    {
        self::bootKernel();
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $paths = static::getContainer()->get(HomeworkPdfStoragePaths::class);
        self::assertInstanceOf(HomeworkPdfStoragePaths::class, $paths);
        $quarantineDir = $paths->quarantineDirectory();
        if (!is_dir($quarantineDir)) {
            self::assertTrue(mkdir($quarantineDir, 0700, true));
        }

        $staleTmp = $quarantineDir.\DIRECTORY_SEPARATOR.'0123456789abcdef0123456789abcdef.tmp';
        $freshBin = $quarantineDir.\DIRECTORY_SEPARATOR.'fedcba9876543210fedcba9876543210.bin';
        file_put_contents($staleTmp, 'stale');
        file_put_contents($freshBin, 'fresh');
        $this->createdFiles[] = $staleTmp;
        $this->createdFiles[] = $freshBin;
        touch($staleTmp, time() - 7200);
        touch($freshBin, time() - 60);

        $app = new Application($kernel);
        $tester = new CommandTester($app->find('app:homework-pdf:purge-quarantine'));

        self::assertSame(2, $tester->execute(['--max-age' => '59']));
        self::assertStringContainsString('quarantine_purge_status=invalid_max_age', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['--max-age' => '3600', '--dry-run' => true]));
        $dryDisplay = $tester->getDisplay();
        self::assertStringContainsString('quarantine_purge_status=ok', $dryDisplay);
        self::assertStringContainsString('dry_run=yes', $dryDisplay);
        self::assertStringContainsString('purged_count=1', $dryDisplay);
        self::assertStringNotContainsString($quarantineDir, $dryDisplay);
        self::assertFileExists($staleTmp);

        self::assertSame(0, $tester->execute(['--max-age' => '3600']));
        $liveDisplay = $tester->getDisplay();
        self::assertStringContainsString('quarantine_purge_status=ok', $liveDisplay);
        self::assertStringContainsString('dry_run=no', $liveDisplay);
        self::assertStringContainsString('purged_count=1', $liveDisplay);
        self::assertFileDoesNotExist($staleTmp);
        self::assertFileExists($freshBin);
    }
}

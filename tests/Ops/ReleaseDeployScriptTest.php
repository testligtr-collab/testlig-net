<?php

declare(strict_types=1);

namespace App\Tests\Ops;

use App\Homework\Pdf\HomeworkPdfStoragePaths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ReleaseDeployScriptTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanupDirs) as $dir) {
            $this->removeTree($dir);
        }
        $this->cleanupDirs = [];
        parent::tearDown();
    }

    public function testDeployScriptsHaveValidBashSyntaxAndProductionGuards(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX bash required for deploy script syntax and execution tests');
        }

        $root = \dirname(__DIR__, 3);
        foreach (['deploy/vds/release-deploy.sh', 'deploy/vds/bootstrap-layout.sh'] as $script) {
            $process = new Process(['bash', '-n', $root.'/'.$script]);
            $process->run();
            self::assertTrue($process->isSuccessful(), $script.': '.$process->getErrorOutput());
        }

        $deployYml = (string) file_get_contents($root.'/.github/workflows/deploy.yml');
        $ghaOnce = (string) file_get_contents($root.'/deploy/vds/gha-deploy-once.sh');
        self::assertStringNotContainsString('TESTLIG_RELEASE_SITE_ROOT', $deployYml);
        self::assertStringNotContainsString('TESTLIG_RELEASE_SITE_ROOT', $ghaOnce);
    }

    public function testReleaseDeployPreservesDurablePdfsAcrossSevenReleasesAndPrunesOldReleases(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX bash + symlinks required for release-deploy test');
        }

        $repoRoot = \dirname(__DIR__, 3);
        $tmpRoot = $this->tempDir('hwpdf-deploy');
        $siteRoot = $tmpRoot.'/site';
        $binDir = $tmpRoot.'/bin';
        $payloadDir = $tmpRoot.'/payload';
        $tarball = $tmpRoot.'/release.tar.gz';

        self::assertTrue(mkdir($siteRoot.'/shared/var/log', 0755, true));
        self::assertTrue(mkdir($siteRoot.'/shared/public/uploads', 0755, true));
        self::assertTrue(mkdir($siteRoot.'/releases', 0755, true));
        self::assertTrue(mkdir($binDir, 0755, true));
        self::assertTrue(mkdir($payloadDir.'/var', 0755, true));
        self::assertTrue(mkdir($payloadDir.'/public', 0755, true));
        self::assertTrue(mkdir($payloadDir.'/bin', 0755, true));

        file_put_contents($siteRoot.'/shared/.env.local', "APP_ENV=prod\nHOMEWORK_PDF_UPLOAD_ENABLED=0\n");
        file_put_contents($payloadDir.'/composer.json', '{"name":"test/app"}');
        file_put_contents($payloadDir.'/bin/console', "<?php\n");

        // Pre-existing release with legacy PDF inside release dir -> must migrate to shared/var/homework-pdf.
        $legacyRelease = $siteRoot.'/releases/20260101000000-000000000000';
        self::assertTrue(mkdir($legacyRelease.'/var/homework-pdf', 0700, true));
        $legacyFile = '11111111111111111111111111111111.bin';
        file_put_contents($legacyRelease.'/var/homework-pdf/'.$legacyFile, 'legacy-pdf-bytes');
        touch($legacyRelease, 1_700_000_000);
        self::assertTrue(symlink($legacyRelease, $siteRoot.'/app'));

        // Stub php, composer, runuser, and curl for isolated non-root bash execution.
        $this->writeExecutable($binDir.'/php', "#!/usr/bin/env bash\nexit 0\n");
        $this->writeExecutable($binDir.'/composer', "#!/usr/bin/env bash\nexit 0\n");
        $this->writeExecutable($binDir.'/runuser', "#!/usr/bin/env bash\nwhile [[ \$# -gt 0 && \$1 != \"--\" ]]; do shift; done\n[[ \"\${1:-}\" == \"--\" ]] && shift\n\"\$@\"\n");
        $this->writeExecutable($binDir.'/curl', "#!/usr/bin/env bash\nout=''\nwhile [[ \$# -gt 0 ]]; do\n  if [[ \"\$1\" == \"-o\" ]]; then out=\"\$2\"; shift 2; continue; fi\n  shift\ndone\n[[ -n \"\$out\" ]] && printf '{\"status\":\"ok\"}' > \"\$out\"\nprintf '200'\n");

        $tar = new Process(['tar', '-czf', $tarball, '-C', $payloadDir, '.']);
        $tar->run();
        self::assertTrue($tar->isSuccessful(), $tar->getErrorOutput());

        $user = trim((string) (new Process(['id', '-un']))->mustRun()->getOutput());
        $group = trim((string) (new Process(['id', '-gn']))->mustRun()->getOutput());

        $basePath = getenv('PATH');
        $effectivePath = $binDir.':'.(\is_string($basePath) && '' !== $basePath ? $basePath : '/usr/local/bin:/usr/bin:/bin');

        $newSharedFile = '22222222222222222222222222222222.bin';
        for ($i = 1; $i <= 7; ++$i) {
            $sha = str_pad(dechex($i), 40, 'a', \STR_PAD_LEFT);
            $process = new Process(
                ['bash', $repoRoot.'/deploy/vds/release-deploy.sh', $sha, $tarball],
                null,
                [
                    'PATH' => $effectivePath,
                    'TESTLIG_RELEASE_SITE_ROOT' => $siteRoot,
                    'APP_USER' => $user,
                    'APP_GROUP' => $group,
                    'PHP_BIN' => $binDir.'/php',
                    'COMPOSER_BIN' => $binDir.'/composer',
                    'HEALTH_URL' => 'https://127.0.0.1/healthz',
                ],
            );
            $process->run();
            self::assertTrue($process->isSuccessful(), 'Deploy '.$i.' failed: '.$process->getOutput().' / '.$process->getErrorOutput());

            // Ensure strictly increasing timestamps on release dirs so KEEP_RELEASES=5 prune order is deterministic.
            $target = realpath($siteRoot.'/app');
            self::assertIsString($target);
            touch($target, 1_700_001_000 + ($i * 10));

            if (1 === $i) {
                $durableRoot = $siteRoot.'/shared/var/homework-pdf';
                self::assertDirectoryExists($durableRoot);
                self::assertFileExists($durableRoot.'/'.$legacyFile);
                self::assertSame('legacy-pdf-bytes', file_get_contents($durableRoot.'/'.$legacyFile));
                file_put_contents($durableRoot.'/'.$newSharedFile, 'new-shared-pdf-bytes');
                chmod($durableRoot.'/'.$newSharedFile, 0600);
            }
        }

        // Legacy release + early releases were pruned (only 5 kept).
        $remainingReleases = glob($siteRoot.'/releases/*', \GLOB_ONLYDIR) ?: [];
        self::assertCount(5, $remainingReleases);
        self::assertDirectoryDoesNotExist($legacyRelease);

        // Durable & quarantine directories survive outside releases/ with 0700 and intact 0600 objects.
        $durableDir = $siteRoot.'/shared/var/homework-pdf';
        $quarantineDir = $siteRoot.'/shared/var/homework-pdf-quarantine';
        self::assertDirectoryExists($durableDir);
        self::assertDirectoryExists($quarantineDir);
        self::assertSame(0700, fileperms($durableDir) & 0777);
        self::assertSame(0700, fileperms($quarantineDir) & 0777);
        self::assertSame(0600, fileperms($durableDir.'/'.$legacyFile) & 0777);
        self::assertSame(0600, fileperms($durableDir.'/'.$newSharedFile) & 0777);
        self::assertSame('legacy-pdf-bytes', file_get_contents($durableDir.'/'.$legacyFile));
        self::assertSame('new-shared-pdf-bytes', file_get_contents($durableDir.'/'.$newSharedFile));

        // Active release must symlink var/log and public/uploads, NEVER var/homework-pdf.
        $activeRelease = realpath($siteRoot.'/app');
        self::assertIsString($activeRelease);
        self::assertTrue(is_link($activeRelease.'/var/log'));
        self::assertTrue(is_link($activeRelease.'/public/uploads'));
        self::assertFalse(is_link($activeRelease.'/var/homework-pdf'));

        $paths = new HomeworkPdfStoragePaths($durableDir, $quarantineDir, $siteRoot.'/app');
        self::assertTrue($paths->isSafeDurableDirectoryReady());
        self::assertTrue($paths->isSafeQuarantineDirectoryReady());

        $unsafePaths = new HomeworkPdfStoragePaths('', '', $siteRoot.'/app');
        self::assertFalse($unsafePaths->isSafeDurableDirectoryReady());
    }

    private function writeExecutable(string $path, string $content): void
    {
        file_put_contents($path, $content);
        chmod($path, 0755);
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.$prefix.'-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0700));
        $this->cleanupDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $this->removeTree($path.\DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($path);
    }
}

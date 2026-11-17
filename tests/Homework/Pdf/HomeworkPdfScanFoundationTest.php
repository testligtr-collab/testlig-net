<?php

declare(strict_types=1);

namespace App\Tests\Homework\Pdf;

use App\Homework\Pdf\ClamdHomeworkPdfScanAdapter;
use App\Homework\Pdf\ClosedHomeworkPdfScanReadiness;
use App\Homework\Pdf\HomeworkPdfQuarantine;
use App\Homework\Pdf\HomeworkPdfQuarantineByteSink;
use App\Homework\Pdf\HomeworkPdfQuarantineFile;
use App\Homework\Pdf\HomeworkPdfQuarantineKeySource;
use App\Homework\Pdf\HomeworkPdfScanException;
use App\Homework\Pdf\HomeworkPdfScanPipeline;
use App\Homework\Pdf\HomeworkPdfScanReadiness;
use App\Homework\Pdf\HomeworkPdfScanVerdict;
use App\Homework\Pdf\HomeworkPdfUploadGate;
use App\LearningContent\Document\PdfDocumentInspector;
use App\Question\Image\QuestionImageInspector;
use App\Question\Image\Scan\ClamdInstreamClient;
use App\Question\Image\Scan\ClamdTransportException;
use App\Question\Image\Scan\QuestionImageScanVerdict;
use App\Tests\Question\Image\RecordingClamdTransport;
use App\Tests\Question\Image\ThrowingClamdTransport;
use PHPUnit\Framework\TestCase;

final class HomeworkPdfScanFoundationTest extends TestCase
{
    public function testGateStaysClosedWhenEnabledWithoutReadiness(): void
    {
        $gate = new HomeworkPdfUploadGate(true, new ClosedHomeworkPdfScanReadiness());
        self::assertFalse($gate->mayAcceptUpload());
        $this->expectException(HomeworkPdfScanException::class);
        $this->expectExceptionMessage(HomeworkPdfUploadGate::DENY_CODE);
        $gate->assertMayAcceptUpload();
    }

    public function testGateOpensOnlyWhenEnabledAndReady(): void
    {
        $ready = new class implements HomeworkPdfScanReadiness {
            public function isReady(): bool
            {
                return true;
            }
        };
        self::assertFalse((new HomeworkPdfUploadGate(false, $ready))->mayAcceptUpload());
        self::assertTrue((new HomeworkPdfUploadGate(true, $ready))->mayAcceptUpload());
    }

    public function testPipelineRejectsWhenGateClosedWithoutCreatingQuarantineFiles(): void
    {
        $dir = $this->tempDir();
        $pipeline = new HomeworkPdfScanPipeline(
            new HomeworkPdfUploadGate(false, new ClosedHomeworkPdfScanReadiness()),
            new PdfDocumentInspector(),
            new HomeworkPdfQuarantine($dir),
            new FakeHomeworkPdfScanAdapter(),
        );
        try {
            $pipeline->stageCleanCandidate($this->minimalPdf(), 'ok.pdf');
            self::fail();
        } catch (HomeworkPdfScanException $exception) {
            self::assertSame(HomeworkPdfUploadGate::DENY_CODE, $exception->getMessage());
        }
        self::assertSame([], glob($dir.\DIRECTORY_SEPARATOR.'*') ?: []);
    }

    public function testCleanInfectedTimeoutUnavailableErrorSizeAndHashAreDistinct(): void
    {
        $map = [
            ["stream: OK\n", HomeworkPdfScanVerdict::Clean],
            ["stream: Eicar-Test-Signature FOUND\n", HomeworkPdfScanVerdict::Infected],
            ["INSTREAM size limit exceeded. ERROR\n", HomeworkPdfScanVerdict::SizeLimit],
            ["stream: Heuristics.Encrypted.Zip ERROR\n", HomeworkPdfScanVerdict::Error],
        ];
        foreach ($map as [$reply, $expected]) {
            $adapter = ClamdHomeworkPdfScanAdapter::withTransport(new RecordingClamdTransport($reply));
            $file = $this->lockedFile('abc');
            try {
                self::assertSame($expected, $adapter->scan($file));
            } finally {
                $file->deleteQuietly();
            }
        }
        $file = $this->lockedFile('abc');
        try {
            $timeout = ClamdHomeworkPdfScanAdapter::withTransport(
                new ThrowingClamdTransport(ClamdTransportException::timedOut()),
            );
            self::assertSame(HomeworkPdfScanVerdict::TimedOut, $timeout->scan($file));
            $unavailable = ClamdHomeworkPdfScanAdapter::withTransport(
                new ThrowingClamdTransport(ClamdTransportException::connectionFailed()),
            );
            self::assertSame(HomeworkPdfScanVerdict::Unavailable, $unavailable->scan($file));
        } finally {
            $file->deleteQuietly();
        }
    }

    public function testDefaultImageClientKeeps10MbWhileHomeworkUsesPdf25Mb(): void
    {
        $transport = new RecordingClamdTransport("stream: OK\n");
        $path = $this->file('x');
        $sha = hash('sha256', 'x');
        $overImage = QuestionImageInspector::MAX_BYTES + 1;
        $overPdf = PdfDocumentInspector::MAX_BYTES + 1;
        try {
            $imageClient = new ClamdInstreamClient($transport);
            self::assertSame(QuestionImageScanVerdict::SizeLimit, $imageClient->scan($path, $overImage, $sha)->verdict);
            self::assertSame('', $transport->sent, 'default image client must not stream above 10MB');

            $pdfClient = new ClamdInstreamClient($transport, PdfDocumentInspector::MAX_BYTES);
            self::assertNotSame(QuestionImageScanVerdict::SizeLimit, $pdfClient->scan($path, $overImage, $sha)->verdict);
            self::assertNotSame('', $transport->sent, 'PDF-capped client must allow sizes between 10MB and 25MB');

            $transport->sent = '';
            self::assertSame(QuestionImageScanVerdict::SizeLimit, $pdfClient->scan($path, $overPdf, $sha)->verdict);
            self::assertSame('', $transport->sent);
            self::assertSame(QuestionImageScanVerdict::SizeLimit, $imageClient->scan($path, $overPdf, $sha)->verdict);

            $homeworkAdapter = ClamdHomeworkPdfScanAdapter::withTransport(new RecordingClamdTransport("stream: OK\n"));
            $underPdf = $this->lockedFile('pdf-cap-probe');
            try {
                self::assertSame(HomeworkPdfScanVerdict::Clean, $homeworkAdapter->scan($underPdf));
            } finally {
                $underPdf->deleteQuietly();
            }
            $overPdfFile = $this->lockedFile('x', $overPdf);
            try {
                self::assertSame(HomeworkPdfScanVerdict::SizeLimit, $homeworkAdapter->scan($overPdfFile));
            } finally {
                $overPdfFile->deleteQuietly();
            }
        } finally {
            unlink($path);
        }
    }

    public function testQuarantineRejectsPreexistingSymlinkTarget(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('symlink semantics differ on Windows CI agents');
        }
        $dir = $this->tempDir();
        $key = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $target = $this->file('payload');
        $link = $dir.\DIRECTORY_SEPARATOR.$key.'.bin';
        self::assertTrue(symlink($target, $link));
        $keys = new class($key) implements HomeworkPdfQuarantineKeySource {
            public function __construct(private string $key)
            {
            }

            public function nextKey(): string
            {
                return $this->key;
            }
        };
        $quarantine = new HomeworkPdfQuarantine($dir, $keys);
        try {
            $quarantine->stage($this->minimalPdf());
            self::fail('expected symlink target rejection');
        } catch (HomeworkPdfScanException $exception) {
            self::assertSame('homework_pdf_path', $exception->getMessage());
            self::assertStringNotContainsString($dir, $exception->getMessage());
            self::assertStringNotContainsString($key, $exception->getMessage());
        } finally {
            @unlink($link);
            @unlink($target);
        }
        self::assertSame([], array_values(array_filter(
            glob($dir.\DIRECTORY_SEPARATOR.'*') ?: [],
            static fn (string $p): bool => !is_link($p) && str_ends_with($p, '.tmp'),
        )));
    }

    public function testQuarantineCleansPartialWriteWithoutLeavingArtifacts(): void
    {
        $dir = $this->tempDir();
        $key = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $keys = new class($key) implements HomeworkPdfQuarantineKeySource {
            public function __construct(private string $key)
            {
            }

            public function nextKey(): string
            {
                return $this->key;
            }
        };
        $sink = new class implements HomeworkPdfQuarantineByteSink {
            public function put(string $path, string $bytes): void
            {
                file_put_contents($path, substr($bytes, 0, 4), \LOCK_EX);
                throw new \RuntimeException('simulated-partial-write');
            }
        };
        $quarantine = new HomeworkPdfQuarantine($dir, $keys, $sink);
        try {
            $quarantine->stage($this->minimalPdf());
            self::fail();
        } catch (HomeworkPdfScanException $exception) {
            self::assertSame('homework_pdf_quarantine', $exception->getMessage());
            self::assertStringNotContainsString('simulated-partial-write', $exception->getMessage());
            self::assertStringNotContainsString($dir, $exception->getMessage());
        }
        self::assertFileDoesNotExist($dir.\DIRECTORY_SEPARATOR.$key.'.tmp');
        self::assertFileDoesNotExist($dir.\DIRECTORY_SEPARATOR.$key.'.bin');
        self::assertSame([], glob($dir.\DIRECTORY_SEPARATOR.'*') ?: []);
    }

    public function testPipelineCleansQuarantineWhenScanIsNotClean(): void
    {
        $dir = $this->tempDir();
        $fake = new FakeHomeworkPdfScanAdapter(HomeworkPdfScanVerdict::Infected);
        $pipeline = $this->openPipeline($dir, $fake);
        try {
            $pipeline->stageCleanCandidate($this->minimalPdf(), 'work.pdf');
            self::fail();
        } catch (HomeworkPdfScanException $exception) {
            self::assertSame('homework_pdf_scan_infected', $exception->getMessage());
            self::assertStringNotContainsString('work.pdf', $exception->getMessage());
            self::assertStringNotContainsString($dir, $exception->getMessage());
            self::assertStringNotContainsString('Eicar', $exception->getMessage());
        }
        self::assertSame([], glob($dir.\DIRECTORY_SEPARATOR.'*') ?: []);
        self::assertCount(1, $fake->calls);
    }

    public function testPipelineRejectsWhenLockedHandleBytesChangeAfterScan(): void
    {
        $dir = $this->tempDir();
        $fake = new FakeHomeworkPdfScanAdapter(
            HomeworkPdfScanVerdict::Clean,
            static function (HomeworkPdfQuarantineFile $file): void {
                // Same-inode truncation while the lock is held; post-scan handle hash must fail closed.
                file_put_contents($file->absolutePath(), '%PDF-1.4 mutated');
            },
        );
        $pipeline = $this->openPipeline($dir, $fake);
        try {
            $pipeline->stageCleanCandidate($this->minimalPdf(), 'work.pdf');
            self::fail();
        } catch (HomeworkPdfScanException $exception) {
            self::assertSame('homework_pdf_scan_hash_mismatch', $exception->getMessage());
            self::assertStringNotContainsString('mutated', $exception->getMessage());
            self::assertStringNotContainsString($dir, $exception->getMessage());
        }
        self::assertSame([], glob($dir.\DIRECTORY_SEPARATOR.'*.bin') ?: []);
    }

    public function testPipelineReturnsCleanStagingAndAbandonDeletesFile(): void
    {
        $dir = $this->tempDir();
        $pipeline = $this->openPipeline($dir, new FakeHomeworkPdfScanAdapter(HomeworkPdfScanVerdict::Clean));
        $staging = $pipeline->stageCleanCandidate($this->minimalPdf(), 'Essay.pdf');
        self::assertSame('Essay.pdf', $staging->displayName());
        self::assertFileExists($staging->file()->absolutePath());
        $staging->abandon();
        self::assertFileDoesNotExist($staging->file()->absolutePath());
    }

    public function testExceptionDuringScanStillDeletesQuarantineAndKeepsSafeMessage(): void
    {
        $dir = $this->tempDir();
        $fake = new class implements \App\Homework\Pdf\HomeworkPdfScanAdapter {
            public function scan(HomeworkPdfQuarantineFile $file): HomeworkPdfScanVerdict
            {
                throw new \RuntimeException('boom-with-secret-path-'.$file->absolutePath());
            }
        };
        $pipeline = $this->openPipeline($dir, $fake);
        try {
            $pipeline->stageCleanCandidate($this->minimalPdf(), 'ok.pdf');
            self::fail();
        } catch (HomeworkPdfScanException $exception) {
            self::assertSame('homework_pdf_scan_error', $exception->getMessage());
            self::assertStringNotContainsString('boom', $exception->getMessage());
            self::assertStringNotContainsString($dir, $exception->getMessage());
            self::assertStringNotContainsString('ok.pdf', $exception->getMessage());
        }
        self::assertSame([], glob($dir.\DIRECTORY_SEPARATOR.'*.bin') ?: []);
    }

    public function testHomeworkPdfClassesDoNotEmitLogs(): void
    {
        $files = glob(\dirname(__DIR__, 2).'/src/Homework/Pdf/*.php') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            self::assertStringNotContainsString('LoggerInterface', $src, $file);
            self::assertStringNotContainsString('->error(', $src, $file);
            self::assertStringNotContainsString('->info(', $src, $file);
            self::assertStringNotContainsString('->warning(', $src, $file);
            self::assertStringNotContainsString('error_log(', $src, $file);
        }
    }

    private function openPipeline(string $dir, \App\Homework\Pdf\HomeworkPdfScanAdapter $adapter): HomeworkPdfScanPipeline
    {
        $ready = new class implements HomeworkPdfScanReadiness {
            public function isReady(): bool
            {
                return true;
            }
        };

        return new HomeworkPdfScanPipeline(
            new HomeworkPdfUploadGate(true, $ready),
            new PdfDocumentInspector(),
            new HomeworkPdfQuarantine($dir),
            $adapter,
        );
    }

    private function minimalPdf(): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    }

    private function lockedFile(string $bytes, ?int $declaredSize = null): HomeworkPdfQuarantineFile
    {
        $path = $this->file($bytes);
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, \LOCK_EX | \LOCK_NB));

        return new HomeworkPdfQuarantineFile(
            $path,
            $handle,
            $declaredSize ?? \strlen($bytes),
            hash('sha256', $bytes),
        );
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hwpdf');
        self::assertIsString($path);
        file_put_contents($path, $bytes);

        return $path;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'hwpdf-q-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0700));

        return $dir;
    }
}

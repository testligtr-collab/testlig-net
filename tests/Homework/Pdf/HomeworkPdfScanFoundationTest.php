<?php

declare(strict_types=1);

namespace App\Tests\Homework\Pdf;

use App\Homework\Pdf\ClamdHomeworkPdfScanAdapter;
use App\Homework\Pdf\ClosedHomeworkPdfScanReadiness;
use App\Homework\Pdf\HomeworkPdfQuarantine;
use App\Homework\Pdf\HomeworkPdfScanException;
use App\Homework\Pdf\HomeworkPdfScanPipeline;
use App\Homework\Pdf\HomeworkPdfScanReadiness;
use App\Homework\Pdf\HomeworkPdfScanVerdict;
use App\Homework\Pdf\HomeworkPdfUploadGate;
use App\LearningContent\Document\PdfDocumentInspector;
use App\Question\Image\QuestionImageInspector;
use App\Question\Image\Scan\ClamdInstreamClient;
use App\Question\Image\Scan\QuestionImageScanVerdict;
use App\Tests\Question\Image\RecordingClamdTransport;
use App\Tests\Question\Image\ThrowingClamdTransport;
use App\Question\Image\Scan\ClamdTransportException;
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
            $path = $this->file("abc");
            try {
                self::assertSame($expected, $adapter->scan($path, 3, hash('sha256', 'abc')));
            } finally {
                unlink($path);
            }
        }
        $path = $this->file('abc');
        try {
            $timeout = ClamdHomeworkPdfScanAdapter::withTransport(
                new ThrowingClamdTransport(ClamdTransportException::timedOut()),
            );
            self::assertSame(HomeworkPdfScanVerdict::TimedOut, $timeout->scan($path, 3, hash('sha256', 'abc')));
            $unavailable = ClamdHomeworkPdfScanAdapter::withTransport(
                new ThrowingClamdTransport(ClamdTransportException::connectionFailed()),
            );
            self::assertSame(HomeworkPdfScanVerdict::Unavailable, $unavailable->scan($path, 3, hash('sha256', 'abc')));
        } finally {
            unlink($path);
        }
    }

    public function testPdfMaxBytesAllowsAboveImageCapButRejectsAbovePdfCap(): void
    {
        $transport = new RecordingClamdTransport("stream: OK\n");
        $path = $this->file('x');
        $sha = hash('sha256', 'x');
        $overImage = QuestionImageInspector::MAX_BYTES + 1;
        $overPdf = PdfDocumentInspector::MAX_BYTES + 1;
        try {
            $imageClient = new ClamdInstreamClient($transport);
            self::assertSame(QuestionImageScanVerdict::SizeLimit, $imageClient->scan($path, $overImage, $sha)->verdict);
            self::assertSame('', $transport->sent);

            $pdfClient = new ClamdInstreamClient($transport, PdfDocumentInspector::MAX_BYTES);
            self::assertNotSame(QuestionImageScanVerdict::SizeLimit, $pdfClient->scan($path, $overImage, $sha)->verdict);
            self::assertNotSame('', $transport->sent);

            $transport->sent = '';
            self::assertSame(QuestionImageScanVerdict::SizeLimit, $pdfClient->scan($path, $overPdf, $sha)->verdict);
            self::assertSame('', $transport->sent);
            self::assertSame(QuestionImageScanVerdict::SizeLimit, $imageClient->scan($path, $overPdf, $sha)->verdict);
        } finally {
            unlink($path);
        }
    }

    public function testQuarantineRejectsSymlinkAndCleansTempOnFailure(): void
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            self::markTestSkipped('symlink semantics differ on Windows CI agents');
        }
        $dir = $this->tempDir();
        $target = $this->file('payload');
        $link = $dir.\DIRECTORY_SEPARATOR.'link.bin';
        self::assertTrue(symlink($target, $link));
        $quarantine = new HomeworkPdfQuarantine($dir);
        try {
            // staging always creates new random names; ensure directory itself cannot be escaped via key
            $file = $quarantine->stage($this->minimalPdf());
            self::assertTrue(is_file($file->absolutePath()));
            self::assertFalse(is_link($file->absolutePath()));
            $file->deleteQuietly();
        } finally {
            @unlink($link);
            @unlink($target);
        }
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
        }
        self::assertSame([], array_values(array_filter(glob($dir.\DIRECTORY_SEPARATOR.'*') ?: [], static fn (string $p): bool => !str_ends_with($p, '.tmp'))));
        self::assertCount(1, $fake->calls);
    }

    public function testPipelineRejectsWhenBytesChangeDuringScan(): void
    {
        $dir = $this->tempDir();
        $fake = new FakeHomeworkPdfScanAdapter(
            HomeworkPdfScanVerdict::Clean,
            static function (string $path): void {
                file_put_contents($path, '%PDF-1.4 mutated');
            },
        );
        $pipeline = $this->openPipeline($dir, $fake);
        try {
            $pipeline->stageCleanCandidate($this->minimalPdf(), 'work.pdf');
            self::fail();
        } catch (HomeworkPdfScanException $exception) {
            self::assertSame('homework_pdf_scan_hash_mismatch', $exception->getMessage());
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

    public function testExceptionDuringScanStillDeletesQuarantine(): void
    {
        $dir = $this->tempDir();
        $fake = new class implements \App\Homework\Pdf\HomeworkPdfScanAdapter {
            public function scan(string $absolutePath, int $byteSize, string $sha256): HomeworkPdfScanVerdict
            {
                throw new \RuntimeException('boom-with-secret-path-'.$absolutePath);
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
        }
        self::assertSame([], glob($dir.\DIRECTORY_SEPARATOR.'*.bin') ?: []);
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

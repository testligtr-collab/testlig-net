<?php

declare(strict_types=1);

namespace App\Tests\LearningContent\Document;

use App\Exception\LearningContentException;
use App\LearningContent\Document\PdfDocumentInspector;
use PHPUnit\Framework\TestCase;

final class PdfDocumentInspectorTest extends TestCase
{
    private PdfDocumentInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new PdfDocumentInspector();
    }

    public function testAcceptsPdfAndNormalizesTraversalName(): void
    {
        self::assertSame('secret.pdf', $this->inspector->inspect($this->pdf(), '../../secret.pdf'));
        self::assertSame('5 KB', PdfDocumentInspector::sizeLabel(5 * 1024));
    }

    public function testRejectsNonPdfMimeAndDangerousPayloads(): void
    {
        $this->assertTypeRejected($this->pdfNamedHtml(), 'notes.pdf');
        $this->assertTypeRejected('<html><script>alert(1)</script></html>', 'page.pdf');
        $this->assertTypeRejected('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'image.pdf');
        $this->assertTypeRejected("PK\x03\x04fake-zip", 'archive.pdf');
        $this->assertTypeRejected("MZ\x90\x00executable", 'tool.pdf');
    }

    public function testRejectsOversizedPdf(): void
    {
        try {
            $this->inspector->inspect(str_repeat('a', PdfDocumentInspector::MAX_BYTES + 1), 'big.pdf');
            self::fail();
        } catch (LearningContentException $e) {
            self::assertSame(PdfDocumentInspector::MESSAGE_SIZE, $e->getMessage());
        }
    }

    public function testRejectsDoubleExtensionAndEmbeddedTraversal(): void
    {
        $this->assertNameRejected('notes.pdf.html');
        $this->assertNameRejected('notes.html.pdf');
        $this->assertNameRejected('..secret.pdf');
        $this->assertNameRejected('a.pdf.pdf');
    }

    private function assertTypeRejected(string $bytes, string $name): void
    {
        try {
            $this->inspector->inspect($bytes, $name);
            self::fail($name);
        } catch (LearningContentException $e) {
            self::assertSame(PdfDocumentInspector::MESSAGE_TYPE, $e->getMessage());
        }
    }

    private function assertNameRejected(string $name): void
    {
        try {
            $this->inspector->inspect($this->pdf(), $name);
            self::fail($name);
        } catch (LearningContentException $e) {
            self::assertSame(PdfDocumentInspector::MESSAGE_NAME, $e->getMessage());
        }
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    }

    private function pdfNamedHtml(): string
    {
        return "<html><body>not a pdf</body></html>\n";
    }
}

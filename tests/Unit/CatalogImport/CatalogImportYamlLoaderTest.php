<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogImport;

use App\Exception\CatalogException;
use App\Service\CatalogImport\CatalogImportYamlLoader;
use PHPUnit\Framework\TestCase;

/**
 * Pure loader tests (no DB). Integration import tests run in CI.
 */
final class CatalogImportYamlLoaderTest extends TestCase
{
    private CatalogImportYamlLoader $loader;
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->loader = new CatalogImportYamlLoader();
        $this->fixturePath = \dirname(__DIR__, 3).\DIRECTORY_SEPARATOR.'data'.\DIRECTORY_SEPARATOR.'catalog'.\DIRECTORY_SEPARATOR.'meb'.\DIRECTORY_SEPARATOR.'tymm-2026'.\DIRECTORY_SEPARATOR.'grade-1-matematik.yaml';
        self::assertFileExists($this->fixturePath);
    }

    public function testOfficialFixtureParses(): void
    {
        $doc = $this->loader->loadFile($this->fixturePath);
        self::assertSame('Matematik', $doc->subjectName);
        self::assertSame(1, $doc->gradeLevel->value);
        self::assertCount(7, $doc->units);
        self::assertSame(19, $doc->topicCount());
        self::assertSame('MAT.1.1', $doc->units[1]->sourceCode);
        self::assertSame(1, $doc->units[1]->occurrence);
        self::assertSame(2, $doc->units[2]->occurrence);
        self::assertSame(3, $doc->units[4]->occurrence);
        self::assertSame('IC:MAT.1.3@1:1', $doc->units[0]->topics[0]->sourceCode);
    }

    public function testUnknownRootKeyRejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'catyml');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, "schema_version: 1\nextra: true\nsource: {}\nsubject: {}\n");
        try {
            $this->expectException(CatalogException::class);
            $this->loader->loadFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testDuplicateUnitOccurrenceRejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'catyml');
        self::assertNotFalse($tmp);
        $yaml = <<<'YAML'
schema_version: 1
source:
  program_id: "2339"
  version: "TYMM-2026"
  program_url: "https://mufredat.meb.gov.tr/ProgramDetay.aspx?PID=2339"
  pdf_url: "https://mufredat.meb.gov.tr/Dosyalar/20268149102521-Matematik%20(1-4)%20DÖP.pdf"
subject:
  name: Matematik
  grade_level: 1
  position: 0
  source_code: MAT
  units:
    - name: "Sayılar ve Nicelikler (1)"
      source_code: "MAT.1.1"
      occurrence: 1
      position: 1
      topics:
        - name: "Rakamlar ve Sayılar"
          position: 1
    - name: "Sayılar ve Nicelikler (2)"
      source_code: "MAT.1.1"
      occurrence: 1
      position: 2
      topics:
        - name: "Uzunluk ve Kütle Ölçme"
          position: 1
YAML;
        file_put_contents($tmp, $yaml);
        try {
            $this->expectException(CatalogException::class);
            $this->loader->loadFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}

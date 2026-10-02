<?php

declare(strict_types=1);

namespace App\Tests\Service\CurriculumImport;

use App\Exception\CurriculumImportException;
use App\Service\CurriculumImport\OfficialCurriculumReconcileDocument;
use App\Service\CurriculumImport\OfficialCurriculumReconcileYamlLoader;
use PHPUnit\Framework\TestCase;

final class OfficialCurriculumReconcileDocumentTest extends TestCase
{
    public function testOfficialFixtureHasSevenThemesAndNineteenOutcomes(): void
    {
        $document = (new OfficialCurriculumReconcileYamlLoader())->loadFile($this->fixture());
        self::assertCount(7, $document->themes);
        self::assertCount(19, $document->outcomes());
        $codes = [];
        $official = [];
        foreach ($document->outcomes() as $outcome) {
            $codes[] = $outcome['code'];
            $official[] = $outcome['officialCode'];
        }
        self::assertSame($codes, array_values(array_unique($codes)));
        self::assertSame($official, array_values(array_unique($official)));
        self::assertSame(
            OfficialCurriculumReconcileDocument::PILOT_DESCRIPTION,
            $this->pilotDescription($document),
        );
        self::assertSame('matematik', $document->subjectCode);
        self::assertSame(1, $document->gradeLevel);
        self::assertSame('TYMM-2026', $document->programVersion);
        $titles = array_map(static fn (array $theme): string => $theme['title'], $document->themes);
        self::assertSame([
            'Sayılar ve Nicelikler (1)',
            'Sayılar ve Nicelikler (2)',
            'Sayılar ve Nicelikler (3)',
            'İşlemlerden Cebirsel Düşünmeye',
            'Nesnelerin Geometrisi (1)',
            'Nesnelerin Geometrisi (2)',
            'Veriye Dayalı Araştırma',
        ], $titles);
    }

    public function testWrongProgramIdentityIsRejected(): void
    {
        $raw = $this->raw();
        $raw['source']['program_id'] = '1999';
        $this->expectException(CurriculumImportException::class);
        OfficialCurriculumReconcileDocument::fromArray($raw);
    }

    public function testWrongChecksumIsRejected(): void
    {
        $loader = new OfficialCurriculumReconcileYamlLoader();
        $tmp = tempnam(sys_get_temp_dir(), 'ocr');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, file_get_contents($this->fixture())."\n");

        try {
            $this->expectException(CurriculumImportException::class);
            $loader->loadFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testDuplicateOutcomeCodeIsRejected(): void
    {
        $raw = $this->raw();
        $raw['themes'][0]['topics'][1]['outcome']['code'] = 'mat_1_1_1';
        $raw['themes'][0]['topics'][1]['outcome']['official_code'] = 'MAT.1.1.1';
        $this->expectException(CurriculumImportException::class);
        OfficialCurriculumReconcileDocument::fromArray($raw);
    }

    private function fixture(): string
    {
        return \dirname(__DIR__, 3)
            .\DIRECTORY_SEPARATOR.'data'
            .\DIRECTORY_SEPARATOR.'curriculum'
            .\DIRECTORY_SEPARATOR.'meb'
            .\DIRECTORY_SEPARATOR.'tymm-2026'
            .\DIRECTORY_SEPARATOR.'grade-1-matematik.yaml';
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(): array
    {
        $parsed = \Symfony\Component\Yaml\Yaml::parseFile($this->fixture());
        self::assertIsArray($parsed);

        return $parsed;
    }

    private function pilotDescription(OfficialCurriculumReconcileDocument $document): string
    {
        foreach ($document->themes as $theme) {
            foreach ($theme['topics'] as $topic) {
                if ('mat_1_3_1' === $topic['outcome']['code']) {
                    return $topic['outcome']['description'];
                }
            }
        }

        self::fail('Pilot outcome missing');
    }
}

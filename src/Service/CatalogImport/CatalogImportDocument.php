<?php

declare(strict_types=1);

namespace App\Service\CatalogImport;

use App\Dto\CatalogSourceAttribution;
use App\Enum\GradeLevel;

/**
 * Strict validated MEB/TYMM catalog fixture document.
 */
final readonly class CatalogImportDocument
{
    public const EXPECTED_SCHEMA_VERSION = 1;
    public const EXPECTED_SOURCE_VERSION = 'TYMM-2026';
    public const EXPECTED_PROGRAM_ID = '2339';
    public const EXPECTED_PROGRAM_URL = 'https://mufredat.meb.gov.tr/ProgramDetay.aspx?PID=2339';
    public const EXPECTED_PDF_URL = 'https://mufredat.meb.gov.tr/Dosyalar/20268149102521-Matematik%20(1-4)%20DÖP.pdf';

    /**
     * @param list<CatalogImportUnitNode> $units
     */
    public function __construct(
        public string $programId,
        public string $sourceVersion,
        public string $programUrl,
        public string $pdfUrl,
        public string $subjectName,
        public GradeLevel $gradeLevel,
        public int $subjectPosition,
        public string $subjectSourceCode,
        public array $units,
    ) {
    }

    public function subjectAttribution(): CatalogSourceAttribution
    {
        return new CatalogSourceAttribution(
            $this->subjectSourceCode,
            $this->sourceVersion,
            $this->programUrl,
            1,
        );
    }

    public function topicCount(): int
    {
        $n = 0;
        foreach ($this->units as $unit) {
            $n += \count($unit->topics);
        }

        return $n;
    }
}

/**
 * @internal
 */
final readonly class CatalogImportUnitNode
{
    /**
     * @param list<CatalogImportTopicNode> $topics
     */
    public function __construct(
        public string $name,
        public string $sourceCode,
        public int $occurrence,
        public int $position,
        public array $topics,
    ) {
    }

    public function attribution(string $sourceVersion, string $sourceUrl): CatalogSourceAttribution
    {
        return new CatalogSourceAttribution($this->sourceCode, $sourceVersion, $sourceUrl, $this->occurrence);
    }
}

/**
 * @internal
 */
final readonly class CatalogImportTopicNode
{
    public function __construct(
        public string $name,
        public int $position,
        public string $sourceCode,
    ) {
    }

    public function attribution(string $sourceVersion, string $sourceUrl): CatalogSourceAttribution
    {
        return new CatalogSourceAttribution($this->sourceCode, $sourceVersion, $sourceUrl, 1);
    }
}

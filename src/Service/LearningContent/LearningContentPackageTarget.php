<?php

declare(strict_types=1);

namespace App\Service\LearningContent;

use App\Enum\LearningContentType;

/**
 * One closed package the importer may read.
 *
 * The stored content type for a lesson package is topic_explanation
 * (Konu anlatımı). There is no separate lesson enum.
 */
final class LearningContentPackageTarget
{
    public const PACKAGE_TYPE_LESSON = 'lesson';

    /**
     * @param list<string>                                             $allowedYamlKeys
     * @param list<array{type: string, variant?: string, items?: int}> $expectedBlocks
     */
    public function __construct(
        public readonly string $directory,
        public readonly string $packageKey,
        public readonly string $packageSlug,
        public readonly string $subjectCode,
        public readonly int $gradeLevel,
        public readonly string $programCode,
        public readonly string $programVersion,
        public readonly string $sourceProgramId,
        public readonly string $outcomeCode,
        public readonly string $officialCode,
        public readonly string $stableCode,
        public readonly string $title,
        public readonly ?string $summary,
        public readonly string $packageType,
        public readonly LearningContentType $contentType,
        public readonly array $allowedYamlKeys,
        public readonly array $expectedBlocks,
    ) {
    }

    public static function mat132(): self
    {
        return new self(
            directory: 'data/content/tymm-2026/grade-1/matematik/mat-1-3-2',
            packageKey: 'tymm-2026/grade-1/matematik/mat-1-3-2',
            packageSlug: 'mat-1-3-2',
            subjectCode: 'matematik',
            gradeLevel: 1,
            programCode: 'mat_grade1_tymm',
            programVersion: 'TYMM-2026',
            sourceProgramId: '2339',
            outcomeCode: 'mat_1_3_2',
            officialCode: 'MAT.1.3.2',
            stableCode: 'mat_1_3_2_es_nesneler',
            title: 'Eş Nesneleri Tanıyalım',
            summary: 'Eş nesneleri renk, biçim ve büyüklüklerine göre karşılaştırmayı öğren.',
            packageType: self::PACKAGE_TYPE_LESSON,
            contentType: LearningContentType::TopicExplanation,
            allowedYamlKeys: [
                'schema_version',
                'package',
                'subject_code',
                'grade_level',
                'program_code',
                'program_version',
                'source_program_id',
                'outcome_code',
                'official_code',
                'official_title',
                'theme_title',
                'curriculum_topic_code',
                'catalog_topic_name',
                'catalog_topic_slug',
                'catalog_unit_slug',
                'status',
                'summary',
                'document',
            ],
            expectedBlocks: [
                ['type' => 'heading'],
                ['type' => 'paragraph'],
                ['type' => 'callout', 'variant' => 'info'],
                ['type' => 'heading'],
                ['type' => 'list', 'items' => 4],
                ['type' => 'heading'],
                ['type' => 'paragraph'],
                ['type' => 'heading'],
                ['type' => 'callout', 'variant' => 'tip'],
            ],
        );
    }
}

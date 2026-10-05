<?php

declare(strict_types=1);

namespace App\Question\Package;

/**
 * One closed question CSV the ops importer may read.
 */
final class QuestionPackageTarget
{
    /**
     * @param list<string> $expectedCodes
     * @param list<string> $expectedAnswers
     */
    public function __construct(
        public readonly string $directory,
        public readonly string $packageKey,
        public readonly string $packageSlug,
        public readonly string $subjectCode,
        public readonly int $gradeLevel,
        public readonly string $programCode,
        public readonly string $programVersion,
        public readonly string $outcomeCode,
        public readonly string $fixtureChecksum,
        public readonly array $expectedCodes,
        public readonly array $expectedAnswers,
        public readonly int $expectedRows,
        public readonly int $expectedColumns,
        public readonly string $questionType,
        public readonly int $pointsEach,
    ) {
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::mat133(),
        ];
    }

    public static function mat133(): self
    {
        return new self(
            directory: 'data/content/tymm-2026/grade-1/matematik/mat-1-3-3',
            packageKey: 'tymm-2026/grade-1/matematik/mat-1-3-3',
            packageSlug: 'mat-1-3-3',
            subjectCode: 'matematik',
            gradeLevel: 1,
            programCode: 'mat_grade1_tymm',
            programVersion: 'TYMM-2026',
            outcomeCode: 'mat_1_3_3',
            fixtureChecksum: '011eab7d740ba6cc72da617b86ca1239c7ceb9ae45b47bab3f0efd19ec615d2d',
            expectedCodes: [
                'c42685528712410e898e1c5b1bfca821',
                'b07a55ad45c9411ab8364d8e1b0dca54',
                '21ea85aae2a94af99079a5fbe24ed443',
                '67a48d575fe54d609fc6260e5de778c7',
                'c648ed3074684de987576a75554bb1a6',
            ],
            expectedAnswers: ['C', 'A', 'D', 'B', 'C'],
            expectedRows: 5,
            expectedColumns: 11,
            questionType: 'single_choice',
            pointsEach: 1,
        );
    }
}

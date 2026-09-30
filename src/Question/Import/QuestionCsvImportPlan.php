<?php

declare(strict_types=1);

namespace App\Question\Import;

/**
 * Dry-run result. Create rows keep the canonical write payload; nothing is persisted here.
 *
 * @phpstan-type RowView array{
 *     line: int,
 *     code: string,
 *     status: string,
 *     message: string,
 *     correct: string,
 *     stem: string
 * }
 * @phpstan-type CreatePayload array{
 *     code: string,
 *     grade: int,
 *     subjectCode: string,
 *     outcomeCode: string,
 *     stem: string,
 *     options: list<string>,
 *     correctIndex: int,
 *     explanation: string
 * }
 */
final class QuestionCsvImportPlan
{
    /**
     * @param list<RowView>             $rows
     * @param array<int, CreatePayload> $creates keyed by CSV line
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $creates,
        public readonly int $createdCount,
        public readonly int $skippedCount,
        public readonly int $errorCount,
        public readonly int $conflictCount,
    ) {
    }

    public function canApply(): bool
    {
        return $this->createdCount > 0 && 0 === $this->errorCount && 0 === $this->conflictCount;
    }

    public function decisionDigest(): string
    {
        $summary = [];
        foreach ($this->rows as $row) {
            $summary[] = [$row['line'], $row['code'], $row['status'], $row['message']];
        }

        return hash('sha256', json_encode($summary, \JSON_THROW_ON_ERROR));
    }
}

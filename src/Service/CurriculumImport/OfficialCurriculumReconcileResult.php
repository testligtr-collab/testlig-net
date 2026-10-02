<?php

declare(strict_types=1);

namespace App\Service\CurriculumImport;

/**
 * Counters for one official-program reconcile. Dry-run leaves applied at 0.
 */
final class OfficialCurriculumReconcileResult
{
    /** @var list<string> */
    private array $lines = [];

    public bool $dryRun = true;
    public bool $applied = false;
    public bool $noop = false;
    public int $programFound = 0;
    public int $themesExpected = 0;
    public int $themesFound = 0;
    public int $themesCreate = 0;
    public int $themesReorder = 0;
    public int $themesConflict = 0;
    public int $topicsExpected = 0;
    public int $topicsFound = 0;
    public int $topicsCreate = 0;
    public int $topicsConflict = 0;
    public int $outcomesExpected = 0;
    public int $outcomesFound = 0;
    public int $outcomesCreate = 0;
    public int $outcomesSkip = 0;
    public int $outcomesConflict = 0;
    public int $pilotPreserved = 0;
    public int $unexpectedRecords = 0;
    public int $questionsAffected = 0;
    public int $assessmentsAffected = 0;
    public int $applyReady = 0;
    public string $planFingerprint = '';

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function hasConflict(): bool
    {
        return $this->themesConflict > 0
            || $this->topicsConflict > 0
            || $this->outcomesConflict > 0
            || $this->unexpectedRecords > 0;
    }
}

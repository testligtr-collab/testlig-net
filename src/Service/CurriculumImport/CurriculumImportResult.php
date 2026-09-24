<?php

declare(strict_types=1);

namespace App\Service\CurriculumImport;

/**
 * Import plan counters and line items (dry-run or apply).
 */
final class CurriculumImportResult
{
    /** @var list<string> */
    private array $lines = [];

    public int $created = 0;
    public int $skipped = 0;
    public int $conflicts = 0;
    public int $errors = 0;
    public bool $applied = false;
    public bool $dryRun = true;
    public bool $published = false;

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
}

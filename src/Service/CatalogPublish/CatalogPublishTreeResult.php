<?php

declare(strict_types=1);

namespace App\Service\CatalogPublish;

/**
 * Counters and plan lines for catalog tree publish (dry-run or apply).
 */
final class CatalogPublishTreeResult
{
    /** @var list<string> */
    private array $lines = [];

    public int $subjectsFound = 0;
    public int $unitsFound = 0;
    public int $topicsFound = 0;
    public int $subjectsToPublish = 0;
    public int $unitsToPublish = 0;
    public int $topicsToPublish = 0;
    public int $subjectsAlreadyPublished = 0;
    public int $unitsAlreadyPublished = 0;
    public int $topicsAlreadyPublished = 0;
    public int $published = 0;
    public int $skipped = 0;
    public bool $applied = false;
    public bool $dryRun = true;
    public bool $noop = false;

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

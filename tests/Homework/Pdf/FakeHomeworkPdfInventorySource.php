<?php

declare(strict_types=1);

namespace App\Tests\Homework\Pdf;

use App\Homework\Pdf\HomeworkPdfInventoryReference;
use App\Homework\Pdf\HomeworkPdfInventorySource;

/**
 * In-memory database side for the read-only inventory tests. Records how it was used so tests can prove the
 * lookups are batched and that nothing is read when the root is refused.
 */
final class FakeHomeworkPdfInventorySource implements HomeworkPdfInventorySource
{
    /** @var list<int> number of keys in every batched lookup */
    public array $lookups = [];

    public int $referenceScans = 0;

    public ?\Throwable $failWith = null;

    /**
     * @param list<HomeworkPdfInventoryReference> $references
     */
    public function __construct(
        public array $references = [],
    ) {
    }

    public function existingStorageKeys(array $storageKeys): array
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        $this->lookups[] = \count($storageKeys);
        $known = [];
        foreach ($this->references as $reference) {
            $known[$reference->storageKey] = true;
        }

        return array_values(array_filter($storageKeys, static fn (string $key): bool => isset($known[$key])));
    }

    public function references(): iterable
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        ++$this->referenceScans;
        $references = $this->references;
        usort($references, static fn (HomeworkPdfInventoryReference $a, HomeworkPdfInventoryReference $b): int => strcmp($a->storageKey, $b->storageKey));

        return $references;
    }
}

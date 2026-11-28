<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Read-only database side of the homework PDF storage inventory. Implementations must not write and must read in
 * bounded batches, never one query per file and never the whole table into memory.
 */
interface HomeworkPdfInventorySource
{
    /**
     * Of the given storage keys, the ones that at least one attachment row refers to. One batched lookup.
     *
     * @param list<string> $storageKeys
     *
     * @return list<string>
     */
    public function existingStorageKeys(array $storageKeys): array;

    /**
     * Every attachment row ordered by storage key ascending, read in bounded pages. A key that several rows
     * share is yielded once per row, so the caller can report it. The database schema forbids that today.
     *
     * @return iterable<HomeworkPdfInventoryReference>
     */
    public function references(): iterable;
}

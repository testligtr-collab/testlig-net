<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Point-in-time result of the read-only homework PDF storage inventory. Counters only: no storage key, no file
 * name, no path and no student data is ever stored here.
 */
final class HomeworkPdfInventoryReport
{
    public const STATUS_OK = 'ok';
    /** The configured durable root does not exist. It is never created. Database references are all "missing". */
    public const STATUS_ROOT_MISSING = 'root_missing';
    /** The root is a symlink or not a directory, sits in a release or public path, or overlaps the quarantine root. */
    public const STATUS_ROOT_UNSAFE = 'root_unsafe';
    /** The root exists but cannot be listed. Not the same as an empty store. */
    public const STATUS_ROOT_UNREADABLE = 'root_unreadable';

    /** Counter names, in output order. Every report holds every name, zero when unused. */
    public const COUNTERS = [
        'disk_files',
        'disk_bytes',
        'matched_files',
        'matched_bytes',
        'unreferenced_young_files',
        'unreferenced_young_bytes',
        'timestamp_unreliable_files',
        'orphan_candidate_files',
        'orphan_candidate_bytes',
        'orphan_candidate_temp_files',
        'unsafe_entries',
        'unsafe_symlink',
        'unsafe_directory',
        'unsafe_special',
        'unsafe_invalid_name',
        'unsafe_stat_failed',
        'db_references',
        'db_declared_bytes',
        'db_physical_bytes',
        'db_missing_file',
        'db_unreadable_file',
        'db_size_mismatch',
        'db_invalid_key',
        'duplicate_reference',
        'ambiguous_owner_references',
        'draft_references',
        'draft_declared_bytes',
        'draft_physical_bytes',
        'submitted_references',
        'submitted_declared_bytes',
        'submitted_physical_bytes',
        'evaluated_references',
        'evaluated_declared_bytes',
        'evaluated_physical_bytes',
        'unknown_lifecycle_references',
        'draft_older_than_threshold',
    ];

    /**
     * @param array<string, int>                                                              $counters
     * @param array<string, array{references: int, declared_bytes: int, physical_bytes: int}> $institutions keyed by institution id
     * @param array{references: int, declared_bytes: int, physical_bytes: int}                $unknownOwner references whose owner is ambiguous
     */
    public function __construct(
        public readonly string $status,
        public readonly bool $storeEmpty,
        public readonly int $orphanMinAgeSeconds,
        public readonly array $counters,
        public readonly array $institutions,
        public readonly array $unknownOwner,
    ) {
    }

    public static function withoutCounts(string $status, int $orphanMinAgeSeconds): self
    {
        return new self(
            $status,
            false,
            $orphanMinAgeSeconds,
            array_fill_keys(self::COUNTERS, 0),
            [],
            ['references' => 0, 'declared_bytes' => 0, 'physical_bytes' => 0],
        );
    }

    public function count(string $name): int
    {
        return $this->counters[$name] ?? 0;
    }

    public function isUsable(): bool
    {
        return self::STATUS_OK === $this->status || self::STATUS_ROOT_MISSING === $this->status;
    }
}

<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Read-only SELECTs for the homework PDF storage inventory.
 *
 * Reads attachments joined to their submission, assignment, recipient and classroom in keyset-paginated pages
 * (storage key, then row id), so memory stays bounded and no row is read twice. The owning institution is the
 * assignment's institution and counts only when the recipient belongs to the same assignment and the classroom
 * belongs to the same institution; otherwise it is reported as unknown (null) instead of being charged to anybody.
 */
final class DbalHomeworkPdfInventorySource implements HomeworkPdfInventorySource
{
    public const PAGE_SIZE = 500;
    public const LOOKUP_MAX_KEYS = 1000;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function existingStorageKeys(array $storageKeys): array
    {
        $found = [];
        foreach (array_chunk($storageKeys, self::LOOKUP_MAX_KEYS) as $chunk) {
            $rows = $this->connection->fetchFirstColumn(
                'SELECT storage_key FROM homework_submission_attachments WHERE storage_key IN (?)',
                [$chunk],
                [ArrayParameterType::STRING],
            );
            foreach ($rows as $key) {
                if (\is_string($key)) {
                    $found[] = $key;
                }
            }
        }

        return $found;
    }

    public function references(): iterable
    {
        $afterKey = '';
        $afterIdHex = '00000000000000000000000000000000';
        $sql = 'SELECT a.storage_key AS storage_key, a.byte_size AS byte_size, a.created_at AS created_at,'
            .' LOWER(HEX(a.id)) AS id_hex, s.status AS status,'
            .' (CASE WHEN s.current_evaluation_id IS NULL THEN 0 ELSE 1 END) AS evaluated,'
            .' LOWER(HEX(asg.institution_id)) AS institution_hex,'
            .' (CASE WHEN r.assignment_id = s.assignment_id AND c.institution_id = asg.institution_id THEN 1 ELSE 0 END) AS owner_consistent'
            .' FROM homework_submission_attachments a'
            .' LEFT JOIN homework_submissions s ON s.id = a.submission_id'
            .' LEFT JOIN homework_assignments asg ON asg.id = s.assignment_id'
            .' LEFT JOIN homework_recipients r ON r.id = s.recipient_id'
            .' LEFT JOIN classrooms c ON c.id = asg.classroom_id'
            .' WHERE (a.storage_key > ? OR (a.storage_key = ? AND a.id > UNHEX(?)))'
            .' ORDER BY a.storage_key ASC, a.id ASC'
            .' LIMIT '.self::PAGE_SIZE;

        while (true) {
            $rows = $this->connection->fetchAllAssociative($sql, [$afterKey, $afterKey, $afterIdHex]);
            foreach ($rows as $row) {
                $key = $row['storage_key'];
                $idHex = $row['id_hex'];
                if (!\is_string($key) || !\is_string($idHex)) {
                    continue;
                }
                $afterKey = $key;
                $afterIdHex = $idHex;
                yield new HomeworkPdfInventoryReference(
                    $key,
                    $this->intValue($row['byte_size']),
                    $this->utcInstant($row['created_at']),
                    $this->lifecycle($row['status'] ?? null, 1 === $this->intValue($row['evaluated'])),
                    1 === $this->intValue($row['owner_consistent']) ? $this->uuidFromHex($row['institution_hex'] ?? null) : null,
                );
            }
            if (\count($rows) < self::PAGE_SIZE) {
                return;
            }
        }
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function utcInstant(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private function lifecycle(mixed $status, bool $evaluated): HomeworkPdfAttachmentLifecycle
    {
        return match ($status) {
            'draft' => HomeworkPdfAttachmentLifecycle::Draft,
            'submitted' => $evaluated ? HomeworkPdfAttachmentLifecycle::Evaluated : HomeworkPdfAttachmentLifecycle::Submitted,
            default => HomeworkPdfAttachmentLifecycle::Unknown,
        };
    }

    private function uuidFromHex(mixed $hex): ?string
    {
        if (!\is_string($hex) || 1 !== preg_match('/^[0-9a-f]{32}$/', $hex)) {
            return null;
        }

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}

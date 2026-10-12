<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

/**
 * Writes bytes, then runs the database save. A failed save attempts remote cleanup.
 * R2 and Bunny report a cleanup failure with a fixed event code. That report does not replace the original save error.
 */
final class LearningDocumentWriteCommit
{
    /**
     * @param callable(string): void $save
     */
    public function commit(LearningDocumentByteStore $store, string $bytes, callable $save): string
    {
        $key = $store->write($bytes);
        try {
            $save($key);
        } catch (\Throwable $error) {
            try {
                $store->deleteQuietly($key);
            } catch (\Throwable) {
            }
            throw $error;
        }

        return $key;
    }
}

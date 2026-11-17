<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

use App\LearningContent\Document\PdfDocumentInspector;
use App\Exception\LearningContentException;

/**
 * Gate → PDF inspect → private quarantine → scan. Fail-closed; no durable store or DB.
 * Does not claim a clean verdict means the file is harmless.
 */
final class HomeworkPdfScanPipeline
{
    public function __construct(
        private readonly HomeworkPdfUploadGate $gate,
        private readonly PdfDocumentInspector $inspector,
        private readonly HomeworkPdfQuarantine $quarantine,
        private readonly HomeworkPdfScanAdapter $scanner,
    ) {
    }

    public function stageCleanCandidate(string $bytes, string $clientName): HomeworkPdfCleanStaging
    {
        $this->gate->assertMayAcceptUpload();
        try {
            $displayName = $this->inspector->inspect($bytes, $clientName);
        } catch (LearningContentException) {
            throw HomeworkPdfScanException::rejected(HomeworkPdfScanVerdict::Error);
        }
        $file = $this->quarantine->stage($bytes);
        try {
            $verdict = $this->scanner->scan($file->absolutePath(), $file->byteSize(), $file->sha256());
            if (HomeworkPdfScanVerdict::Clean !== $verdict) {
                $file->deleteQuietly();
                throw HomeworkPdfScanException::rejected($verdict);
            }
            $rehash = hash_file('sha256', $file->absolutePath());
            $resize = filesize($file->absolutePath());
            if (
                !\is_string($rehash)
                || !\is_int($resize)
                || $resize !== $file->byteSize()
                || !hash_equals($file->sha256(), $rehash)
                || is_link($file->absolutePath())
                || !is_file($file->absolutePath())
            ) {
                $file->deleteQuietly();
                throw HomeworkPdfScanException::rejected(HomeworkPdfScanVerdict::HashMismatch);
            }

            return new HomeworkPdfCleanStaging($file, $displayName);
        } catch (HomeworkPdfScanException $exception) {
            $file->deleteQuietly();
            throw $exception;
        } catch (\Throwable) {
            $file->deleteQuietly();
            throw HomeworkPdfScanException::rejected(HomeworkPdfScanVerdict::Error);
        }
    }
}

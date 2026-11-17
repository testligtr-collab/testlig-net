<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

use App\Exception\LearningContentException;
use App\LearningContent\Document\PdfDocumentInspector;

/**
 * Gate → PDF inspect → private quarantine → same-handle scan. Fail-closed; no durable store or DB.
 * Clean means only the locked-handle bytes matched the streamed scan; durable+DB must re-verify.
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
            $verdict = $this->scanner->scan($file);
            if (HomeworkPdfScanVerdict::Clean !== $verdict) {
                $this->discardAndReject($file, $verdict);
            }
            $file->hashFromLockedHandle();

            return new HomeworkPdfCleanStaging($file, $displayName);
        } catch (HomeworkPdfScanException $exception) {
            $file->deleteQuietly();
            throw $exception;
        } catch (\Throwable) {
            if (!$file->delete()) {
                throw HomeworkPdfScanException::quarantine();
            }
            throw HomeworkPdfScanException::rejected(HomeworkPdfScanVerdict::Error);
        }
    }

    /**
     * @return never
     */
    private function discardAndReject(HomeworkPdfQuarantineFile $file, HomeworkPdfScanVerdict $verdict): void
    {
        if (!$file->delete()) {
            throw HomeworkPdfScanException::quarantine();
        }
        throw HomeworkPdfScanException::rejected($verdict);
    }
}

<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Server-side gate for optional homework PDF upload. Default closed.
 * Hiding UI is not security; callers must assert before accepting bytes.
 */
final class HomeworkPdfUploadGate
{
    public const DENY_CODE = 'homework_pdf_upload_closed';

    public function __construct(
        private readonly bool $uploadEnabled,
        private readonly HomeworkPdfScanReadiness $readiness,
    ) {
    }

    /**
     * Readiness may change between pre-scan and post-scan checks (external/runtime state).
     *
     * @phpstan-impure
     */
    public function mayAcceptUpload(): bool
    {
        return $this->uploadEnabled && $this->readiness->isReady();
    }

    public function assertMayAcceptUpload(): void
    {
        if (!$this->mayAcceptUpload()) {
            throw HomeworkPdfScanException::closed();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

use App\LearningContent\Document\PdfDocumentInspector;
use App\Question\Image\Scan\ClamdInstreamClient;
use App\Question\Image\Scan\QuestionImageScanVerdict;

/**
 * Maps clamd INSTREAM replies onto homework PDF verdicts.
 * Uses PdfDocumentInspector::MAX_BYTES, not the question-image 10MB cap.
 */
final class ClamdHomeworkPdfScanAdapter implements HomeworkPdfScanAdapter
{
    public function __construct(
        private readonly ClamdInstreamClient $clamd,
    ) {
    }

    public static function withTransport(\App\Question\Image\Scan\ClamdInstreamTransport $transport): self
    {
        return new self(new ClamdInstreamClient($transport, PdfDocumentInspector::MAX_BYTES));
    }

    public function scan(string $absolutePath, int $byteSize, string $sha256): HomeworkPdfScanVerdict
    {
        if ($byteSize < 1 || $byteSize > PdfDocumentInspector::MAX_BYTES) {
            return HomeworkPdfScanVerdict::SizeLimit;
        }
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            return HomeworkPdfScanVerdict::HashMismatch;
        }
        if (is_link($absolutePath) || !is_file($absolutePath)) {
            return HomeworkPdfScanVerdict::Error;
        }
        $result = $this->clamd->scan($absolutePath, $byteSize, $sha256);
        if (!$result->streamMatched && QuestionImageScanVerdict::Clean === $result->verdict) {
            return HomeworkPdfScanVerdict::HashMismatch;
        }

        return $this->map($result->verdict);
    }

    private function map(QuestionImageScanVerdict $verdict): HomeworkPdfScanVerdict
    {
        return match ($verdict) {
            QuestionImageScanVerdict::Clean => HomeworkPdfScanVerdict::Clean,
            QuestionImageScanVerdict::Infected => HomeworkPdfScanVerdict::Infected,
            QuestionImageScanVerdict::TimedOut => HomeworkPdfScanVerdict::TimedOut,
            QuestionImageScanVerdict::ConnectionFailed,
            QuestionImageScanVerdict::StaleSignature => HomeworkPdfScanVerdict::Unavailable,
            QuestionImageScanVerdict::SizeLimit => HomeworkPdfScanVerdict::SizeLimit,
            QuestionImageScanVerdict::HashMismatch => HomeworkPdfScanVerdict::HashMismatch,
            QuestionImageScanVerdict::Error,
            QuestionImageScanVerdict::Malformed,
            QuestionImageScanVerdict::CleanupFailed,
            QuestionImageScanVerdict::Unchanged => HomeworkPdfScanVerdict::Error,
        };
    }
}

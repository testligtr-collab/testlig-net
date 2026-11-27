<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

use App\LearningContent\Document\PdfDocumentInspector;

/**
 * Instantiates homework PDF durable and quarantine stores from resolved paths.
 */
final class HomeworkPdfStorageFactory
{
    public static function createDurableStore(HomeworkPdfStoragePaths $paths): HomeworkPdfDurableStore
    {
        return new HomeworkPdfDurableStore($paths->durableDirectory());
    }

    public static function createQuarantine(HomeworkPdfStoragePaths $paths): HomeworkPdfQuarantine
    {
        return new HomeworkPdfQuarantine($paths->quarantineDirectory(), PdfDocumentInspector::MAX_BYTES);
    }
}

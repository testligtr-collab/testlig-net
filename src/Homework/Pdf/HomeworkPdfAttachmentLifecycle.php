<?php

declare(strict_types=1);

namespace App\Homework\Pdf;

/**
 * Lifecycle of the submission a PDF attachment belongs to. Read-only inventory vocabulary: it is derived from
 * the submission status and its current evaluation and never changes what may be kept or deleted.
 */
enum HomeworkPdfAttachmentLifecycle: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Evaluated = 'evaluated';
    /** A status this inventory does not know. Kept and counted, never treated as stale. */
    case Unknown = 'unknown';
}

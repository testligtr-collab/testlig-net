<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Student homework object permissions. Global roles never grant these alone.
 */
final class HomeworkSubmissionPermission
{
    public const VIEW = 'HOMEWORK_SUBMISSION_VIEW';
    public const SAVE_DRAFT = 'HOMEWORK_SUBMISSION_SAVE_DRAFT';
    public const SUBMIT = 'HOMEWORK_SUBMISSION_SUBMIT';
}

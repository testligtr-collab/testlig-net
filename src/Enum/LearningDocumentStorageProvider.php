<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Provider stored on a PDF asset. This is not the StoredMediaAsset provider enum.
 */
enum LearningDocumentStorageProvider: string
{
    case Local = 'local';
    case R2 = 'r2';
}

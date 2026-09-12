<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Provider-neutral storage backend label. No SDK integration in Stage 2.15.
 */
enum StoredMediaStorageProvider: string
{
    case Local = 'local';
    case S3 = 's3';
    case R2 = 'r2';
    case Bunny = 'bunny';
}

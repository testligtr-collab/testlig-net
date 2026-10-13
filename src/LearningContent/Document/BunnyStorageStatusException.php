<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

/**
 * A Bunny status probe failed before an HTTP status was available.
 * This is not proof that the object is missing.
 */
final class BunnyStorageStatusException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Bunny durum sorgusu tamamlanamadı.');
    }
}

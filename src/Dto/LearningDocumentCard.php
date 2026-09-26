<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Editor card for a PDF. No storage key, path, or entity id.
 */
final readonly class LearningDocumentCard
{
    public function __construct(
        public string $name,
        public string $sizeLabel,
        public string $statusLabel,
        public string $handle,
        public bool $canApprove,
        public bool $canAttach,
    ) {
    }
}

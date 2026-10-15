<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * One consistent signature view. A missing field is not represented here.
 */
final class ClamavSignatureSnapshot
{
    public function __construct(
        public readonly int $main,
        public readonly int $daily,
        public readonly int $bytecode,
        public readonly int $loadedDaily,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->main === $other->main
            && $this->daily === $other->daily
            && $this->bytecode === $other->bytecode
            && $this->loadedDaily === $other->loadedDaily;
    }
}

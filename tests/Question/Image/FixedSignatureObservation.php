<?php

declare(strict_types=1);

namespace App\Tests\Question\Image;

use App\Question\Image\Scan\ClamavSignatureObservation;
use App\Question\Image\Scan\ClamavSignatureSnapshot;

final class FixedSignatureObservation implements ClamavSignatureObservation
{
    private int $calls = 0;

    public function __construct(
        private readonly ?ClamavSignatureSnapshot $first,
        private readonly ?ClamavSignatureSnapshot $second = null,
    ) {
    }

    public function capture(): ?ClamavSignatureSnapshot
    {
        ++$this->calls;

        return 1 === $this->calls ? $this->first : ($this->second ?? $this->first);
    }
}

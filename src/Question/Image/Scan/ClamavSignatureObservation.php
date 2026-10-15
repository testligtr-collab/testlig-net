<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

interface ClamavSignatureObservation
{
    public function capture(): ?ClamavSignatureSnapshot;
}

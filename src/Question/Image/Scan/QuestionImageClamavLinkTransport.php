<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

interface QuestionImageClamavLinkTransport
{
    public function ping(): string;

    public function version(): string;
}

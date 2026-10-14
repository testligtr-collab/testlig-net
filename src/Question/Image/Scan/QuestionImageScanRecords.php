<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

use App\Entity\User;
use Symfony\Component\Uid\Uuid;

interface QuestionImageScanRecords
{
    public function describeQuestionImage(Uuid $assetId): QuestionImageScanSnapshot;

    public function commitQuestionImageScan(User $actor, QuestionImageScanSnapshot $expected, QuestionImageScanVerdict $verdict): void;
}

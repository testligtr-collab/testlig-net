<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\User;
use App\Question\Image\QuestionRevisionPendingImage;

/**
 * Scans only the pending image linked to the question revision that was shown.
 */
final class QuestionImageScanAction
{
    public function __construct(
        private readonly QuestionRevisionPendingImage $images,
        private readonly QuestionImageScanner $scanner,
    ) {
    }

    public function scan(User $actor, Question $question, QuestionRevision $revision): string
    {
        $assetId = $this->images->sole($question, $revision);
        if (null === $assetId) {
            return QuestionImageScanMessage::ineligible();
        }

        return QuestionImageScanMessage::forVerdict(
            $this->scanner->scanPendingQuestionImage($actor, $assetId),
        );
    }
}

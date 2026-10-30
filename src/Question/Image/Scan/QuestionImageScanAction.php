<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\User;
use App\Question\Image\QuestionRevisionPendingImage;

/**
 * Scans each pending image linked to the question revision that was shown.
 * A clean verdict is the only path that marks that asset ready.
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
        $assetIds = $this->images->pending($question, $revision);
        if ([] === $assetIds) {
            return QuestionImageScanMessage::ineligible();
        }
        $message = QuestionImageScanMessage::forVerdict(QuestionImageScanVerdict::Clean);
        foreach ($assetIds as $assetId) {
            $verdict = $this->scanner->scanPendingQuestionImage($actor, $assetId);
            $message = QuestionImageScanMessage::forVerdict($verdict);
            if (QuestionImageScanVerdict::Clean !== $verdict) {
                return $message;
            }
        }

        return $message;
    }
}

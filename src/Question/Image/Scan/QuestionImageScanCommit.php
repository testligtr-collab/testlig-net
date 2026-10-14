<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

use App\Entity\StoredMediaAsset;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\StoredMediaScanStatus;
use App\Exception\LearningContentException;
use App\Service\ActiveVerifiedUserPolicy;

/**
 * Applies one question-image scan result with the existing asset transitions.
 * This entry requires an active verified super admin and does not change other media roles.
 */
final class QuestionImageScanCommit
{
    public function __construct(
        private readonly ActiveVerifiedUserPolicy $actors = new ActiveVerifiedUserPolicy(),
    ) {
    }

    /**
     * @param callable(SecurityAuditAction): void $audit
     */
    public function apply(
        User $actor,
        StoredMediaAsset $asset,
        QuestionImageScanSnapshot $expected,
        QuestionImageScanVerdict $verdict,
        \DateTimeImmutable $now,
        callable $audit,
    ): void {
        if (!$this->actors->isActiveVerifiedSuperAdmin($actor)) {
            throw LearningContentException::unauthorized();
        }
        if (!$expected->matches($asset) || !$expected->isPendingImage()) {
            throw LearningContentException::conflict();
        }
        if (QuestionImageScanVerdict::Clean === $verdict) {
            $asset->markScanStatus(StoredMediaScanStatus::Clean, $now);
            $audit(SecurityAuditAction::StoredMediaAssetScanUpdated);
            $asset->markReady($now);
            $audit(SecurityAuditAction::StoredMediaAssetMarkedReady);

            return;
        }
        if (QuestionImageScanVerdict::Infected === $verdict) {
            $asset->markScanStatus(StoredMediaScanStatus::Infected, $now);
            $audit(SecurityAuditAction::StoredMediaAssetScanUpdated);

            return;
        }
        if (\in_array($verdict, [
            QuestionImageScanVerdict::Error,
            QuestionImageScanVerdict::SizeLimit,
            QuestionImageScanVerdict::Malformed,
            QuestionImageScanVerdict::ConnectionFailed,
            QuestionImageScanVerdict::TimedOut,
            QuestionImageScanVerdict::HashMismatch,
        ], true)) {
            $asset->markScanStatus(StoredMediaScanStatus::Failed, $now);
            $audit(SecurityAuditAction::StoredMediaAssetScanUpdated);

            return;
        }

        throw LearningContentException::invalidInput('question_image_scan');
    }
}

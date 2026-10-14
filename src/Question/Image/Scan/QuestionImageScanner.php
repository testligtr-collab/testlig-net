<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

use App\Entity\User;
use App\Enum\LearningContentFailureReason;
use App\Exception\LearningContentException;
use App\Exception\QuestionException;
use App\Question\Image\QuestionImageObjectKey;
use App\Question\Image\QuestionImageStoreResolver;
use App\Service\ActiveVerifiedUserPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Reads one pending question image, checks its recorded bytes, then scans that copy.
 * There is no HTTP route, console command, or queue worker for this entry.
 */
final class QuestionImageScanner
{
    public function __construct(
        private readonly ActiveVerifiedUserPolicy $actors,
        private readonly QuestionImageScanRecords $records,
        private readonly QuestionImageStoreResolver $stores,
        private readonly ClamdInstreamClient $clamd,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function scanPendingQuestionImage(User $actor, Uuid $assetId): QuestionImageScanVerdict
    {
        if (!$this->actors->isActiveVerifiedSuperAdmin($actor)) {
            throw LearningContentException::unauthorized();
        }
        $snapshot = $this->records->describeQuestionImage($assetId);
        if (!$snapshot->isPendingImage()) {
            return QuestionImageScanVerdict::Unchanged;
        }
        QuestionImageObjectKey::assertForAsset($snapshot->storageKey, $snapshot->scope, $snapshot->institutionId);

        $copy = null;
        $verdict = null;
        $cleanupFailed = false;
        $commit = false;
        try {
            $copy = $this->stores->storeFor($snapshot->provider)->read($snapshot->storageKey, $snapshot->byteSize);
            if ($copy->byteSize() !== $snapshot->byteSize || !hash_equals($snapshot->sha256, $copy->sha256())) {
                $verdict = QuestionImageScanVerdict::HashMismatch;
                $commit = true;
            } else {
                $result = $this->clamd->scan($copy->path(), $snapshot->byteSize, $snapshot->sha256);
                $verdict = $result->verdict;
                $commit = $result->streamMatched;
            }
        } catch (QuestionException $exception) {
            if ('question_image_cleanup' === $exception->getMessage()) {
                $cleanupFailed = true;
            } else {
                $verdict = 'question_image_limit' === $exception->getMessage()
                    ? QuestionImageScanVerdict::SizeLimit
                    : QuestionImageScanVerdict::Error;
                $commit = true;
            }
        } finally {
            if ($copy instanceof QuestionImageScanCopy) {
                try {
                    $copy->delete();
                } catch (\Throwable) {
                    $cleanupFailed = true;
                }
            }
        }
        if ($cleanupFailed || !$verdict instanceof QuestionImageScanVerdict) {
            $this->logger->error('question_image.scan_failed');

            return QuestionImageScanVerdict::CleanupFailed;
        }
        if (QuestionImageScanVerdict::Clean !== $verdict) {
            $this->logger->error('question_image.scan_failed');
        }
        if (!$commit) {
            return $verdict;
        }
        try {
            $this->records->commitQuestionImageScan($actor, $snapshot, $verdict);
        } catch (LearningContentException $exception) {
            if (LearningContentFailureReason::Conflict === $exception->getReason()) {
                return QuestionImageScanVerdict::Unchanged;
            }
            throw $exception;
        }

        return $verdict;
    }
}

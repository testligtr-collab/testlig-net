<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Exception\LearningContentException;
use App\Question\Image\Scan\QuestionImageScanRecords;
use App\Repository\QuestionRevisionOptionRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Pending images referenced by one question revision.
 * A foreign asset is not scannable. Ready is not granted here.
 */
final class QuestionRevisionPendingImage
{
    public function __construct(
        private readonly QuestionRevisionOptionRepository $options,
        private readonly QuestionImageScanRecords $records,
    ) {
    }

    public function sole(Question $question, QuestionRevision $revision): ?Uuid
    {
        $pending = $this->pending($question, $revision);

        return 1 === \count($pending) ? $pending[0] : null;
    }

    /**
     * @return list<Uuid>
     */
    public function pending(Question $question, QuestionRevision $revision): array
    {
        if (!$revision->getQuestion()->getId()->equals($question->getId())) {
            return [];
        }
        $ids = [];
        foreach ([$revision->getStemContent(), $revision->getExplanationContent()] as $document) {
            $this->collect($document, $ids);
        }
        foreach ($this->options->findByRevision($revision) as $option) {
            $this->collect($option->getContent(), $ids);
        }
        $pending = [];
        foreach ($ids as $assetId) {
            if ($this->isPendingForQuestion($question, $assetId)) {
                $pending[] = $assetId;
            }
        }

        return $pending;
    }

    private function isPendingForQuestion(Question $question, Uuid $assetId): bool
    {
        try {
            $snapshot = $this->records->describeQuestionImage($assetId);
        } catch (LearningContentException) {
            return false;
        }
        $institutionId = $question->getInstitution()?->getId();
        $sameInstitution = (null === $snapshot->institutionId && null === $institutionId)
            || ($snapshot->institutionId instanceof Uuid && $institutionId instanceof Uuid && $snapshot->institutionId->equals($institutionId));

        return $snapshot->isPendingImage()
            && $snapshot->scope->value === $question->getScope()->value
            && $sameInstitution;
    }

    /**
     * @param array<string, mixed>|null $document
     * @param array<string, Uuid>       $ids
     */
    private function collect(?array $document, array &$ids): void
    {
        if (!\is_array($document) || !isset($document['blocks']) || !\is_array($document['blocks'])) {
            return;
        }
        foreach ($document['blocks'] as $block) {
            if (!\is_array($block) || 'image_reference' !== ($block['type'] ?? null)) {
                continue;
            }
            $mediaId = $block['mediaId'] ?? null;
            if (!\is_string($mediaId) || !Uuid::isValid($mediaId)) {
                continue;
            }
            $ids[$mediaId] = Uuid::fromString($mediaId);
        }
    }
}

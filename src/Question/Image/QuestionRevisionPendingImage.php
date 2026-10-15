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
 * The single pending image referenced by the current question revision.
 * Zero or several references, or a foreign asset, is not scannable.
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
        if (!$revision->getQuestion()->getId()->equals($question->getId())) {
            return null;
        }
        $ids = [];
        foreach ([$revision->getStemContent(), $revision->getExplanationContent()] as $document) {
            $this->collect($document, $ids);
        }
        foreach ($this->options->findByRevision($revision) as $option) {
            $this->collect($option->getContent(), $ids);
        }
        if (1 !== \count($ids)) {
            return null;
        }
        $assetId = array_values($ids)[0];
        try {
            $snapshot = $this->records->describeQuestionImage($assetId);
        } catch (LearningContentException) {
            return null;
        }
        $institutionId = $question->getInstitution()?->getId();
        $sameInstitution = (null === $snapshot->institutionId && null === $institutionId)
            || ($snapshot->institutionId instanceof Uuid && $institutionId instanceof Uuid && $snapshot->institutionId->equals($institutionId));
        if (!$snapshot->isPendingImage() || $snapshot->scope->value !== $question->getScope()->value || !$sameInstitution) {
            return null;
        }

        return $assetId;
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

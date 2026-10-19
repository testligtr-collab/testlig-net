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
 * Reads the one image linked to this revision. A GET does not change the asset.
 */
final class QuestionRevisionImageStatus
{
    public function __construct(
        private readonly QuestionRevisionOptionRepository $options,
        private readonly QuestionImageScanRecords $records,
    ) {
    }

    public function label(Question $question, QuestionRevision $revision): ?string
    {
        if (!$revision->getQuestion()->getId()->equals($question->getId())) {
            return null;
        }
        $ids = [];
        $invalid = false;
        foreach ([$revision->getStemContent(), $revision->getExplanationContent()] as $document) {
            $this->collect($document, $ids, $invalid);
        }
        foreach ($this->options->findByRevision($revision) as $option) {
            $this->collect($option->getContent(), $ids, $invalid);
        }
        if ([] === $ids && !$invalid) {
            return null;
        }
        if ($invalid || 1 !== \count($ids)) {
            return 'Görsel referansı geçersiz';
        }
        $assetId = array_values($ids)[0];
        try {
            $snapshot = $this->records->describeQuestionImage($assetId);
        } catch (LearningContentException) {
            return 'Görsel referansı geçersiz';
        }
        $institutionId = $question->getInstitution()?->getId();
        $sameInstitution = (null === $snapshot->institutionId && null === $institutionId)
            || ($snapshot->institutionId instanceof Uuid && $institutionId instanceof Uuid && $snapshot->institutionId->equals($institutionId));
        if ($snapshot->scope->value !== $question->getScope()->value || !$sameInstitution) {
            return 'Görsel referansı geçersiz';
        }

        return QuestionImageStatusLabel::fromSnapshot($snapshot);
    }

    /**
     * @param array<string, mixed>|null $document
     * @param array<string, Uuid>       $ids
     */
    private function collect(?array $document, array &$ids, bool &$invalid): void
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
                $invalid = true;
                continue;
            }
            $ids[$mediaId] = Uuid::fromString($mediaId);
        }
    }
}

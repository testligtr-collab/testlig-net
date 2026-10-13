<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Entity\Institution;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\StoredMediaAsset;
use App\Enum\QuestionScope;
use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\StoredMediaScanStatus;
use App\Exception\QuestionException;
use App\Question\Content\QuestionContentDocument;
use App\Repository\QuestionRevisionOptionRepository;
use App\Repository\QuestionRevisionRepository;
use App\Service\InstitutionalFreshEntityLoader;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Uid\Uuid;

/**
 * Drafts may reference a pending question image. Review and publish require a clean ready asset.
 */
final class QuestionImageReferenceGuard
{
    public function __construct(
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly QuestionRevisionRepository $revisions,
        private readonly QuestionRevisionOptionRepository $options,
    ) {
    }

    /**
     * @param list<array{stableKey: string, content: QuestionContentDocument|array<string, mixed>, position: int}> $options
     */
    public function assertDraftReferences(
        QuestionScope $scope,
        ?Institution $institution,
        QuestionContentDocument $stem,
        ?QuestionContentDocument $explanation,
        array $options,
    ): void {
        $documents = [$stem->toArray()];
        if ($explanation instanceof QuestionContentDocument) {
            $documents[] = $explanation->toArray();
        }
        foreach ($options as $option) {
            $content = $option['content'];
            $documents[] = $content instanceof QuestionContentDocument ? $content->toArray() : $content;
        }
        foreach ($this->mediaIds($documents) as $mediaId) {
            $this->assertDraftAsset($scope, $institution, $mediaId);
        }
    }

    public function assertCurrentRevisionMayLeaveDraft(Question $question): void
    {
        $revision = $this->revisions->findForQuestionNumber($question, $question->getCurrentRevisionNumber());
        if (!$revision instanceof QuestionRevision) {
            throw QuestionException::notFound();
        }
        $documents = [$revision->getStemContent()];
        $explanation = $revision->getExplanationContent();
        if (\is_array($explanation)) {
            $documents[] = $explanation;
        }
        foreach ($this->options->findByRevision($revision) as $option) {
            $documents[] = $option->getContent();
        }
        foreach ($this->mediaIds($documents) as $mediaId) {
            $asset = $this->locked($mediaId);
            if (!$this->sameTenant($question->getScope(), $question->getInstitution(), $asset)) {
                throw QuestionException::imageRejected('question_image_scope');
            }
            if (StoredMediaAssetStatus::Ready !== $asset->getStatus() || StoredMediaScanStatus::Clean !== $asset->getScanStatus()) {
                throw QuestionException::imageRejected('question_image_pending');
            }
        }
    }

    private function assertDraftAsset(QuestionScope $scope, ?Institution $institution, Uuid $mediaId): void
    {
        $asset = $this->locked($mediaId);
        if (!$this->sameTenant($scope, $institution, $asset) || StoredMediaAssetStatus::Archived === $asset->getStatus()) {
            throw QuestionException::imageRejected('question_image_scope');
        }
    }

    private function locked(Uuid $mediaId): StoredMediaAsset
    {
        $asset = $this->freshEntities->findFreshLockedStoredMediaAsset($mediaId, LockMode::PESSIMISTIC_WRITE);
        if (!$asset instanceof StoredMediaAsset || StoredMediaAssetKind::Image !== $asset->getKind()) {
            throw QuestionException::imageRejected('question_image_scope');
        }

        return $asset;
    }

    private function sameTenant(QuestionScope $scope, ?Institution $institution, StoredMediaAsset $asset): bool
    {
        return match ($scope) {
            QuestionScope::Platform => StoredMediaAssetScope::Platform === $asset->getScope() && null === $asset->getInstitution(),
            QuestionScope::Institution => $this->sameInstitution($institution, $asset),
        };
    }

    private function sameInstitution(?Institution $institution, StoredMediaAsset $asset): bool
    {
        $assetInstitution = $asset->getInstitution();

        return StoredMediaAssetScope::Institution === $asset->getScope()
            && $institution instanceof Institution
            && $assetInstitution instanceof Institution
            && $assetInstitution->getId()->equals($institution->getId());
    }

    /**
     * @param list<array<string, mixed>> $documents
     *
     * @return list<Uuid>
     */
    private function mediaIds(array $documents): array
    {
        $ids = [];
        foreach ($documents as $document) {
            $blocks = $document['blocks'] ?? null;
            if (!\is_array($blocks)) {
                continue;
            }
            foreach ($blocks as $block) {
                if (!\is_array($block) || 'image_reference' !== ($block['type'] ?? null)) {
                    continue;
                }
                $mediaId = $block['mediaId'] ?? null;
                if (!\is_string($mediaId) || !Uuid::isValid($mediaId)) {
                    throw QuestionException::imageRejected('question_image_scope');
                }
                $ids[$mediaId] = Uuid::fromString($mediaId);
            }
        }

        return array_values($ids);
    }
}

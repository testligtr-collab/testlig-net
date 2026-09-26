<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CatalogSubjectListItem;
use App\Dto\CatalogTopicLessonListItem;
use App\Dto\CatalogTopicListItem;
use App\Dto\CatalogUnitListItem;
use App\Dto\StudentContent\StudentContentBlockView;
use App\Dto\StudentTopicDetail;
use App\Entity\CatalogSubject;
use App\Entity\CatalogTopic;
use App\Entity\CatalogTopicLesson;
use App\Entity\CatalogUnit;
use App\Entity\LearningDocumentAsset;
use App\Entity\User;
use App\Enum\CatalogPublicationStatus;
use App\Enum\GradeLevel;
use App\Enum\LearningContentStatus;
use App\LearningContent\Document\PdfDocumentInspector;
use App\LearningContent\StudentView\StudentContentBlockNormalizer;
use App\Repository\CatalogSubjectRepository;
use App\Repository\CatalogTopicLessonRepository;
use App\Repository\CatalogTopicRepository;
use App\Repository\CatalogUnitRepository;
use App\Repository\LearningDocumentAssetRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Published topic + accessible published placements for the student surface.
 *
 * Visibility requires AND of: subject/unit/topic published, placement published,
 * LearningContent published with sealed published revision, and access gate allow.
 * Bodies are exposed only as normalized typed block views (no raw JSON / storageKey).
 */
final class StudentTopicContentQuery
{
    public function __construct(
        private readonly CatalogSubjectRepository $subjects,
        private readonly CatalogUnitRepository $units,
        private readonly CatalogTopicRepository $topics,
        private readonly CatalogTopicLessonRepository $lessons,
        private readonly LearningContentAccessGate $accessGate,
        private readonly StudentContentBlockNormalizer $blockNormalizer,
        private readonly LearningDocumentAssetRepository $documents,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function getPublishedTopicDetail(
        GradeLevel $grade,
        string $subjectSlug,
        string $unitSlug,
        string $topicSlug,
        User $actor,
    ): ?StudentTopicDetail {
        $subject = $this->subjects->findOneByGradeAndSlug($grade, $subjectSlug);
        if (!$subject instanceof CatalogSubject || CatalogPublicationStatus::Published !== $subject->getStatus()) {
            return null;
        }

        $unit = $this->units->findOneBySubjectAndSlug($subject, $unitSlug);
        if (!$unit instanceof CatalogUnit || CatalogPublicationStatus::Published !== $unit->getStatus()) {
            return null;
        }

        $topic = $this->topics->findOneByUnitAndSlug($unit, $topicSlug);
        if (!$topic instanceof CatalogTopic || CatalogPublicationStatus::Published !== $topic->getStatus()) {
            return null;
        }

        $visibleLessons = [];
        /** @var list<Uuid> $assetIds */
        $assetIds = [];
        foreach ($this->lessons->findPublishedOrderedByTopic($topic) as $lesson) {
            if (!$this->isLessonVisibleToStudent($lesson, $actor)) {
                continue;
            }
            $visibleLessons[] = $lesson;
            $publishedRevision = $lesson->getLearningContent()->getPublishedRevision();
            if (null !== $publishedRevision && $publishedRevision->isSealed()) {
                foreach ($this->documentIds($publishedRevision->getStructuredContent()) as $assetId) {
                    $assetIds[] = $assetId;
                }
            }
        }
        $assets = $this->documents->findMappedByIds($assetIds);

        $lessonItems = [];
        foreach ($visibleLessons as $lesson) {
            $publishedRevision = $lesson->getLearningContent()->getPublishedRevision();
            $blocks = [];
            if (null !== $publishedRevision && $publishedRevision->isSealed()) {
                $blocks = $this->blockNormalizer->normalize(
                    $publishedRevision->getStructuredContent(),
                    function (int $index, string $assetId, string $label) use ($assets, $subject, $unit, $topic, $lesson): ?StudentContentBlockView {
                        $asset = $assets[$assetId] ?? null;
                        if (null === $asset || !$asset->isServable()) {
                            return null;
                        }

                        return StudentContentBlockView::document(
                            $label,
                            $asset->getOriginalName(),
                            PdfDocumentInspector::sizeLabel($asset->getByteSize()),
                            $this->urls->generate('app_student_learning_document', [
                                'subjectSlug' => $subject->getSlug(),
                                'unitSlug' => $unit->getSlug(),
                                'topicSlug' => $topic->getSlug(),
                                'lessonSlug' => $lesson->getSlug(),
                                'index' => $index,
                            ]),
                        );
                    },
                );
            }

            $lessonItems[] = new CatalogTopicLessonListItem(
                $lesson->getSlug(),
                $lesson->getDisplayTitle(),
                $lesson->getSummary(),
                $lesson->getPosition(),
                $blocks,
            );
        }

        return new StudentTopicDetail(
            CatalogSubjectListItem::fromEntity($subject),
            CatalogUnitListItem::fromEntity($unit),
            CatalogTopicListItem::fromEntity($topic),
            $lessonItems,
        );
    }

    /**
     * @param array<string, mixed> $structured
     *
     * @return list<Uuid>
     */
    private function documentIds(array $structured): array
    {
        $blocks = $structured['blocks'] ?? [];
        if (!\is_array($blocks)) {
            return [];
        }
        $ids = [];
        foreach ($blocks as $block) {
            if (!\is_array($block) || 'document' !== ($block['type'] ?? null)) {
                continue;
            }
            $id = $block['assetId'] ?? null;
            if (\is_string($id) && Uuid::isValid($id)) {
                $ids[] = Uuid::fromString($id);
            }
        }

        return $ids;
    }

    public function findServableDocument(
        GradeLevel $grade,
        string $subjectSlug,
        string $unitSlug,
        string $topicSlug,
        string $lessonSlug,
        int $index,
        User $actor,
    ): ?LearningDocumentAsset {
        if ($index < 0) {
            return null;
        }
        $subject = $this->subjects->findOneByGradeAndSlug($grade, $subjectSlug);
        if (!$subject instanceof CatalogSubject || CatalogPublicationStatus::Published !== $subject->getStatus()) {
            return null;
        }
        $unit = $this->units->findOneBySubjectAndSlug($subject, $unitSlug);
        if (!$unit instanceof CatalogUnit || CatalogPublicationStatus::Published !== $unit->getStatus()) {
            return null;
        }
        $topic = $this->topics->findOneByUnitAndSlug($unit, $topicSlug);
        if (!$topic instanceof CatalogTopic || CatalogPublicationStatus::Published !== $topic->getStatus()) {
            return null;
        }

        foreach ($this->lessons->findPublishedOrderedByTopic($topic) as $lesson) {
            if ($lesson->getSlug() !== $lessonSlug || !$this->isLessonVisibleToStudent($lesson, $actor)) {
                continue;
            }
            $revision = $lesson->getLearningContent()->getPublishedRevision();
            if (null === $revision || !$revision->isSealed()) {
                return null;
            }
            $blocks = $revision->getStructuredContent()['blocks'] ?? [];
            if (!\is_array($blocks) || !isset($blocks[$index]) || !\is_array($blocks[$index])) {
                return null;
            }
            $block = $blocks[$index];
            if ('document' !== ($block['type'] ?? null)) {
                return null;
            }
            $id = $block['assetId'] ?? null;
            if (!\is_string($id) || !Uuid::isValid($id)) {
                return null;
            }
            $asset = $this->documents->find(Uuid::fromString($id));

            return $asset instanceof LearningDocumentAsset && $asset->isServable() ? $asset : null;
        }

        return null;
    }

    private function isLessonVisibleToStudent(CatalogTopicLesson $lesson, User $actor): bool
    {
        if (CatalogPublicationStatus::Published !== $lesson->getVisibilityStatus()) {
            return false;
        }

        $content = $lesson->getLearningContent();
        if (LearningContentStatus::Published !== $content->getStatus()) {
            return false;
        }

        $publishedRevision = $content->getPublishedRevision();
        if (null === $publishedRevision || !$publishedRevision->isSealed()) {
            return false;
        }

        return $this->accessGate->evaluate($content->getId(), $actor)->allowed;
    }
}

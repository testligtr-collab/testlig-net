<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CatalogSubjectListItem;
use App\Dto\CatalogTopicLessonListItem;
use App\Dto\CatalogTopicListItem;
use App\Dto\CatalogUnitListItem;
use App\Dto\StudentTopicDetail;
use App\Entity\CatalogSubject;
use App\Entity\CatalogTopic;
use App\Entity\CatalogTopicLesson;
use App\Entity\CatalogUnit;
use App\Entity\User;
use App\Enum\CatalogPublicationStatus;
use App\Enum\GradeLevel;
use App\Enum\LearningContentStatus;
use App\LearningContent\StudentView\StudentContentBlockNormalizer;
use App\Repository\CatalogSubjectRepository;
use App\Repository\CatalogTopicLessonRepository;
use App\Repository\CatalogTopicRepository;
use App\Repository\CatalogUnitRepository;

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

        $lessonItems = [];
        foreach ($this->lessons->findPublishedOrderedByTopic($topic) as $lesson) {
            if (!$this->isLessonVisibleToStudent($lesson, $actor)) {
                continue;
            }

            $publishedRevision = $lesson->getLearningContent()->getPublishedRevision();
            $blocks = [];
            if (null !== $publishedRevision && $publishedRevision->isSealed()) {
                $blocks = $this->blockNormalizer->normalize($publishedRevision->getStructuredContent());
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

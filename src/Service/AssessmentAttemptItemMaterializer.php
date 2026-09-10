<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentAttemptItem;
use App\Entity\AssessmentItem;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentRevision;
use App\Entity\AssessmentSection;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionType;
use App\Exception\AssessmentAttemptException;
use App\Repository\QuestionRevisionOptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Builds immutable AssessmentAttemptItem presentation snapshots from a publication graph.
 * Never loads or touches QuestionAnswerKey.
 */
final class AssessmentAttemptItemMaterializer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuestionRevisionOptionRepository $optionRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<AssessmentAttemptItem>
     */
    public function materialize(AssessmentAttempt $attempt, AssessmentPublication $publication): array
    {
        $revision = $publication->getAssessmentRevision();
        $sections = $this->loadSectionsOrdered($revision);
        $itemsBySection = $this->loadItemsGroupedBySection($revision);

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $presentationPosition = 1;
        $result = [];

        foreach ($sections as $section) {
            $sectionId = $section->getId()->toRfc4122();
            $sectionItems = $itemsBySection[$sectionId] ?? [];
            if ($this->shouldShuffleQuestions($section, $revision)) {
                $sectionItems = $this->fisherYatesShuffle($sectionItems);
            }

            foreach ($sectionItems as $assessmentItem) {
                $questionRevision = $assessmentItem->getQuestionRevision();
                $result[] = AssessmentAttemptItem::create(
                    $attempt,
                    $section,
                    $assessmentItem,
                    $assessmentItem->getQuestion(),
                    $questionRevision,
                    $section->getPosition(),
                    $assessmentItem->getPosition(),
                    $presentationPosition,
                    $this->resolveOptionOrderJson($assessmentItem, $revision),
                    $assessmentItem->isRequired(),
                    $assessmentItem->getPoints(),
                    $assessmentItem->getPenaltyPoints(),
                    $questionRevision->getContentHash(),
                    $now,
                );
                ++$presentationPosition;
            }
        }

        return $result;
    }

    /**
     * @return list<AssessmentSection>
     */
    private function loadSectionsOrdered(AssessmentRevision $revision): array
    {
        /** @var list<AssessmentSection> $sections */
        $sections = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(AssessmentSection::class, 's')
            ->andWhere('s.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->orderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $sections;
    }

    /**
     * @return array<string, list<AssessmentItem>>
     */
    private function loadItemsGroupedBySection(AssessmentRevision $revision): array
    {
        /** @var list<AssessmentItem> $items */
        $items = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(AssessmentItem::class, 'i')
            ->andWhere('i.assessmentRevision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->orderBy('i.position', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($items as $item) {
            $sectionId = $item->getSection()->getId()->toRfc4122();
            $grouped[$sectionId][] = $item;
        }

        return $grouped;
    }

    private function shouldShuffleQuestions(AssessmentSection $section, AssessmentRevision $revision): bool
    {
        return QuestionOrderMode::Shuffle === $section->getQuestionOrderMode()
            || QuestionOrderMode::Shuffle === $revision->getQuestionOrderMode();
    }

    /**
     * @return list<string>|null
     */
    private function resolveOptionOrderJson(AssessmentItem $item, AssessmentRevision $revision): ?array
    {
        $type = $item->getQuestionRevision()->getType();
        if (\in_array($type, [QuestionType::TrueFalse, QuestionType::Numeric, QuestionType::ShortAnswer], true)) {
            return null;
        }

        $mode = $item->getOptionOrderMode() ?? $revision->getOptionOrderMode();
        $keys = $this->optionRepository->listStableKeysForRevisionOrdered(
            $item->getQuestionRevision()->getId(),
        );
        if ([] === $keys) {
            throw AssessmentAttemptException::invalidInput('Choice question is missing options for materialization.');
        }

        if (OptionOrderMode::Shuffle === $mode) {
            return $this->fisherYatesShuffle($keys);
        }

        return $keys;
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private function fisherYatesShuffle(array $items): array
    {
        $n = \count($items);
        for ($i = $n - 1; $i > 0; --$i) {
            $j = random_int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }
}

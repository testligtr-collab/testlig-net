<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CatalogSubjectListItem;
use App\Dto\CatalogTopicListItem;
use App\Dto\CatalogUnitListItem;
use App\Entity\CatalogSubject;
use App\Entity\CatalogUnit;
use App\Enum\CatalogPublicationStatus;
use App\Enum\GradeLevel;
use App\Repository\CatalogSubjectRepository;
use App\Repository\CatalogTopicRepository;
use App\Repository\CatalogUnitRepository;

/**
 * Published-only student catalog queries (HTTP-agnostic).
 */
final class StudentCatalogQuery
{
    public function __construct(
        private readonly CatalogSubjectRepository $subjects,
        private readonly CatalogUnitRepository $units,
        private readonly CatalogTopicRepository $topics,
    ) {
    }

    /**
     * @return list<CatalogSubjectListItem>
     */
    public function listPublishedSubjectsForGrade(GradeLevel $grade): array
    {
        return array_map(
            static fn (CatalogSubject $s): CatalogSubjectListItem => CatalogSubjectListItem::fromEntity($s),
            $this->subjects->findPublishedByGrade($grade),
        );
    }

    public function findPublishedSubjectForGrade(GradeLevel $grade, string $subjectSlug): ?CatalogSubjectListItem
    {
        $subject = $this->subjects->findOneByGradeAndSlug($grade, $subjectSlug);
        if (!$subject instanceof CatalogSubject || CatalogPublicationStatus::Published !== $subject->getStatus()) {
            return null;
        }

        return CatalogSubjectListItem::fromEntity($subject);
    }

    /**
     * @return list<CatalogUnitListItem>|null null when subject missing/unpublished for grade
     */
    public function listPublishedUnitsForGradeSubject(GradeLevel $grade, string $subjectSlug): ?array
    {
        $subject = $this->requirePublishedSubject($grade, $subjectSlug);
        if (null === $subject) {
            return null;
        }

        return array_map(
            static fn (CatalogUnit $u): CatalogUnitListItem => CatalogUnitListItem::fromEntity($u),
            $this->units->findPublishedBySubject($subject),
        );
    }

    /**
     * @return array{subject: CatalogSubjectListItem, unit: CatalogUnitListItem, topics: list<CatalogTopicListItem>}|null
     */
    public function getPublishedUnitDetail(GradeLevel $grade, string $subjectSlug, string $unitSlug): ?array
    {
        $subject = $this->requirePublishedSubject($grade, $subjectSlug);
        if (null === $subject) {
            return null;
        }

        $unit = $this->units->findOneBySubjectAndSlug($subject, $unitSlug);
        if (!$unit instanceof CatalogUnit || CatalogPublicationStatus::Published !== $unit->getStatus()) {
            return null;
        }

        $topics = array_map(
            static fn ($t): CatalogTopicListItem => CatalogTopicListItem::fromEntity($t),
            $this->topics->findPublishedByUnit($unit),
        );

        return [
            'subject' => CatalogSubjectListItem::fromEntity($subject),
            'unit' => CatalogUnitListItem::fromEntity($unit),
            'topics' => $topics,
        ];
    }

    private function requirePublishedSubject(GradeLevel $grade, string $subjectSlug): ?CatalogSubject
    {
        $subject = $this->subjects->findOneByGradeAndSlug($grade, $subjectSlug);
        if (!$subject instanceof CatalogSubject || CatalogPublicationStatus::Published !== $subject->getStatus()) {
            return null;
        }

        return $subject;
    }
}

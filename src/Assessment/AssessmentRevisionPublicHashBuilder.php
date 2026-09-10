<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Entity\AssessmentItem;
use App\Entity\AssessmentRevision;
use App\Entity\AssessmentSection;

/**
 * Builds the public assessment revision structure used for publicContentHash.
 *
 * Must never include answer payloads, HMAC, email, or secrets.
 *
 * @phpstan-type PublicItem array{
 *     position: int,
 *     questionId: string,
 *     questionRevisionId: string,
 *     questionRevisionNumber: int,
 *     questionPublicContentHash: string,
 *     subjectId: string,
 *     points: string,
 *     penaltyPoints: string,
 *     required: bool,
 *     optionOrderMode: string|null
 * }
 * @phpstan-type PublicSection array{
 *     position: int,
 *     title: string,
 *     instructions: string|null,
 *     durationSeconds: int|null,
 *     questionOrderMode: string,
 *     items: list<PublicItem>
 * }
 */
final class AssessmentRevisionPublicHashBuilder
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<PublicSection> $sections
     *
     * @return array<string, mixed>
     */
    public function build(
        string $title,
        ?string $description,
        ?string $instructions,
        ?int $durationSeconds,
        string $navigationMode,
        string $questionOrderMode,
        string $optionOrderMode,
        string $resultReleasePolicy,
        ?string $passScorePercentage,
        array $sections,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'instructions' => $instructions,
            'durationSeconds' => $durationSeconds,
            'navigationMode' => $navigationMode,
            'questionOrderMode' => $questionOrderMode,
            'optionOrderMode' => $optionOrderMode,
            'resultReleasePolicy' => $resultReleasePolicy,
            'passScorePercentage' => $passScorePercentage,
            'sections' => $sections,
            'schemaVersion' => $schemaVersion,
        ];
    }

    /**
     * Rebuild public hash payload from a persisted revision graph.
     *
     * Sections sorted by position then section UUID; items by position then item UUID.
     *
     * @param list<AssessmentSection> $sections
     * @param list<AssessmentItem>    $items
     *
     * @return array<string, mixed>
     */
    public function buildFromGraph(
        AssessmentRevision $revision,
        array $sections,
        array $items,
    ): array {
        $sectionsSorted = $sections;
        usort(
            $sectionsSorted,
            static function (AssessmentSection $a, AssessmentSection $b): int {
                $byPosition = $a->getPosition() <=> $b->getPosition();
                if (0 !== $byPosition) {
                    return $byPosition;
                }

                return $a->getId()->toRfc4122() <=> $b->getId()->toRfc4122();
            },
        );

        $itemsBySection = [];
        foreach ($items as $item) {
            $itemsBySection[$item->getSection()->getId()->toRfc4122()][] = $item;
        }

        $publicSections = [];
        foreach ($sectionsSorted as $section) {
            $sectionItems = $itemsBySection[$section->getId()->toRfc4122()] ?? [];
            usort(
                $sectionItems,
                static function (AssessmentItem $a, AssessmentItem $b): int {
                    $byPosition = $a->getPosition() <=> $b->getPosition();
                    if (0 !== $byPosition) {
                        return $byPosition;
                    }

                    return $a->getId()->toRfc4122() <=> $b->getId()->toRfc4122();
                },
            );

            $publicItems = [];
            foreach ($sectionItems as $item) {
                $publicItems[] = [
                    'position' => $item->getPosition(),
                    'questionId' => $item->getQuestion()->getId()->toRfc4122(),
                    'questionRevisionId' => $item->getQuestionRevision()->getId()->toRfc4122(),
                    'questionRevisionNumber' => $item->getQuestionRevision()->getRevisionNumber(),
                    'questionPublicContentHash' => $item->getQuestionRevision()->getContentHash(),
                    'subjectId' => $item->getQuestion()->getSubject()->getId()->toRfc4122(),
                    'points' => $item->getPoints(),
                    'penaltyPoints' => $item->getPenaltyPoints(),
                    'required' => $item->isRequired(),
                    'optionOrderMode' => $item->getOptionOrderMode()?->value,
                ];
            }

            $publicSections[] = [
                'position' => $section->getPosition(),
                'title' => $section->getTitle(),
                'instructions' => $section->getInstructions(),
                'durationSeconds' => $section->getDurationSeconds(),
                'questionOrderMode' => $section->getQuestionOrderMode()->value,
                'items' => $publicItems,
            ];
        }

        return $this->build(
            $revision->getTitle(),
            $revision->getDescription(),
            $revision->getInstructions(),
            $revision->getDurationSeconds(),
            $revision->getNavigationMode()->value,
            $revision->getQuestionOrderMode()->value,
            $revision->getOptionOrderMode()->value,
            $revision->getResultReleasePolicy()->value,
            $revision->getPassScorePercentage(),
            $publicSections,
            $revision->getSchemaVersion(),
        );
    }
}

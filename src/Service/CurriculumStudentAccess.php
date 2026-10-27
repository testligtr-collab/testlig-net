<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Enum\CurriculumAccessState;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\SubjectStatus;
use App\Repository\CurriculumAccessGateRepository;

/**
 * Student access is the whole parent chain. A node's own status is not rewritten
 * when a parent is closed or opened again.
 */
final class CurriculumStudentAccess
{
    public function __construct(private readonly CurriculumAccessGateRepository $gates)
    {
    }

    public function studentOpen(CurriculumLearningOutcome $outcome): bool
    {
        $program = $outcome->getCurriculumProgram();

        return CurriculumStatus::Published === $program->getStatus()
            && CurriculumAccessState::Active === $program->getStudentAccess()
            && SubjectStatus::Active === $program->getSubject()->getStatus()
            && $this->levelOpen($program->getGradeLevel())
            && $this->chainActive($outcome);
    }

    public function chainActive(CurriculumLearningOutcome $outcome): bool
    {
        return $this->contentOpen($outcome->getStatus())
            && $this->unitActive($outcome->getUnit())
            && $this->topicChainActive($outcome->getTopic());
    }

    public function topicOpen(CurriculumTopic $topic): bool
    {
        $program = $topic->getUnit()->getProgram();

        return CurriculumStatus::Published === $program->getStatus()
            && CurriculumAccessState::Active === $program->getStudentAccess()
            && SubjectStatus::Active === $program->getSubject()->getStatus()
            && $this->levelOpen($program->getGradeLevel())
            && $this->unitActive($topic->getUnit())
            && $this->topicChainActive($topic);
    }

    private function levelOpen(GradeLevel $grade): bool
    {
        return $this->gates->isOpen('stage', $grade->stageKey())
            && $this->gates->isOpen('grade', (string) $grade->value);
    }

    private function unitActive(CurriculumUnit $unit): bool
    {
        return $this->contentOpen($unit->getStatus());
    }

    private function topicChainActive(CurriculumTopic $topic): bool
    {
        $current = $topic;
        $depth = 0;
        while ($depth < 32) {
            if (!$this->contentOpen($current->getStatus())) {
                return false;
            }
            $parent = $current->getParent();
            if (null === $parent) {
                return true;
            }
            $current = $parent;
            ++$depth;
        }

        return false;
    }

    private function contentOpen(CurriculumContentStatus $status): bool
    {
        return CurriculumContentStatus::Active === $status;
    }
}

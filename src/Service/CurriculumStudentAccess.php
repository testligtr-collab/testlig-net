<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\SubjectStatus;

/**
 * Student access is the whole parent chain. A node's own status is not rewritten
 * when a parent is closed or opened again.
 */
final class CurriculumStudentAccess
{
    public function studentOpen(CurriculumLearningOutcome $outcome): bool
    {
        $program = $outcome->getCurriculumProgram();

        return CurriculumStatus::Published === $program->getStatus()
            && SubjectStatus::Active === $program->getSubject()->getStatus()
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
            && SubjectStatus::Active === $program->getSubject()->getStatus()
            && $this->unitActive($topic->getUnit())
            && $this->topicChainActive($topic);
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

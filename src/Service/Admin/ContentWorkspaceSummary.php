<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\ContentWorkspaceSummaryView;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\AssessmentRepository;
use App\Repository\QuestionRepository;
use App\Security\AdminAuthorization;

/**
 * Cheap, permission-scoped counts. Teachers see only their own content totals.
 */
final class ContentWorkspaceSummary
{
    public function __construct(
        private readonly AdminAuthorization $adminAuthorization,
        private readonly AdminLearningContentQuery $contents,
        private readonly QuestionRepository $questions,
        private readonly AssessmentRepository $assessments,
    ) {
    }

    public function forActor(User $actor): ?ContentWorkspaceSummaryView
    {
        if (!$this->adminAuthorization->canViewLearningContentWorkspace($actor)) {
            return null;
        }

        $actorId = $actor->getId();

        return new ContentWorkspaceSummaryView(
            draftContents: $this->contents->listContents($actorId, [
                'status' => 'draft',
                'page' => 1,
                'page_size' => 1,
            ])->totalCount,
            reviewContents: $this->contents->listContents($actorId, [
                'status' => 'in_review',
                'page' => 1,
                'page_size' => 1,
            ])->totalCount,
            myQuestions: $this->questions->countCreatedBy($actor),
            myTests: $this->assessments->countCreatedBy($actor),
            ownContentCounts: $this->seesOnlyOwnContent($actor),
            canCreateContent: $this->adminAuthorization->canManageLearningContentWorkspace($actor),
            canCreateQuestion: $this->adminAuthorization->canAuthorQuestions($actor),
            canCreateTest: $this->adminAuthorization->canAuthorTests($actor),
            canOpenAdminHome: $this->adminAuthorization->canAccessAdminShell($actor),
        );
    }

    private function seesOnlyOwnContent(User $actor): bool
    {
        $roles = $actor->getRoles();
        if (!\in_array(UserRole::Teacher->value, $roles, true)) {
            return false;
        }

        return !$this->adminAuthorization->canMapCatalogCanonical($actor)
            && !\in_array(UserRole::Moderator->value, $roles, true);
    }
}

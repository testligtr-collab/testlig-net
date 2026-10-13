<?php

declare(strict_types=1);

namespace App\Question\Image;

use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\QuestionScope;
use App\Enum\UserRole;
use App\Exception\QuestionException;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\InstitutionalFreshEntityLoader;

class QuestionImageAuthorizer
{
    public function __construct(
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
    ) {
    }

    public function assertMayUpload(User $actor, QuestionScope $scope, ?Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw QuestionException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }
        if (QuestionScope::Platform === $scope) {
            if (null !== $institution) {
                throw QuestionException::scopeMismatch();
            }
            foreach ([UserRole::Admin, UserRole::HeadTeacher, UserRole::ExpertTeacher, UserRole::Teacher] as $role) {
                if (\in_array($role->value, $actor->getRoles(), true)) {
                    return;
                }
            }
            throw QuestionException::unauthorized();
        }
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw QuestionException::unauthorized();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership || InstitutionMembershipStatus::Active !== $membership->getStatus()) {
            throw QuestionException::unauthorized();
        }
        if (!\in_array($membership->getRole(), [
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
            InstitutionMembershipRole::Teacher,
        ], true)) {
            throw QuestionException::unauthorized();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\CurriculumProgram;
use App\Entity\User;
use App\Security\Authorization\CurriculumProgramAuthorizationSnapshot;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Published curriculum is VIEW-able by any active+verified user.
 * Draft / manage / publish / retire: SUPER_ADMIN only.
 * Status is read from a DBAL snapshot — never trust a managed entity.
 *
 * @extends Voter<string, CurriculumProgram>
 */
final class CurriculumVoter extends Voter
{
    public function __construct(
        private readonly RequestScopedInstitutionAuthLookup $authLookup,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof CurriculumProgram && \in_array($attribute, CurriculumPermission::all(), true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $tokenUser = $token->getUser();
        if (!$tokenUser instanceof User) {
            return false;
        }

        $user = $this->authLookup->getUserSnapshot($tokenUser->getId());
        if (null === $user || !$user->isActiveAndVerified()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (CurriculumPermission::VIEW !== $attribute) {
            return false;
        }

        $program = $this->authLookup->getCurriculumProgramSnapshot($subject->getId());

        return $program instanceof CurriculumProgramAuthorizationSnapshot && $program->isPublished();
    }
}

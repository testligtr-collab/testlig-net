<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\UserRole;
use App\Service\StudentProfileManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Resolves the default post-login destination for students (when no safe target path).
 */
final class StudentLoginRedirector
{
    public function __construct(
        private readonly StudentProfileManager $profiles,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function isStudent(User $user): bool
    {
        return \in_array(UserRole::Student->value, $user->getRoles(), true);
    }

    public function defaultPathFor(User $user): string
    {
        if (!$this->isStudent($user)) {
            return $this->urlGenerator->generate('app_account');
        }

        if ($this->profiles->isOnboardingCompleted($user)) {
            return $this->urlGenerator->generate('app_student_dashboard');
        }

        return $this->urlGenerator->generate('app_student_onboarding');
    }
}

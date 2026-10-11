<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\UserManagementFailureReason;
use App\Enum\UserRole;
use App\Exception\InvalidUserTransitionException;
use App\Exception\OnboardingApplicationException;
use App\Repository\TeacherApplicationRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Grants ROLE_TEACHER from an approved application. Approval itself never grants the role.
 */
final class TeacherApplicationRoleGrant
{
    private const REASON_PREFIX = 'teacher_application_';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FreshUserLoader $freshUsers,
        private readonly TeacherApplicationRepository $applications,
        private readonly UserGlobalRoleManager $roles,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
    ) {
    }

    public function grant(User $actor, Uuid $applicationId): bool
    {
        $reason = $this->reasonFor($applicationId);
        $actorId = $actor->getId();
        $application = $this->applications->findOneById($applicationId);
        if (null === $application) {
            throw OnboardingApplicationException::notFound();
        }
        $applicantId = $application->getUser()->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use ($actorId, $applicantId, $applicationId, $reason): bool {
                $lockedUsers = $this->freshUsers->findFreshLockedUsers(
                    [$actorId, $applicantId],
                    LockMode::PESSIMISTIC_WRITE,
                );
                $freshActor = $lockedUsers[$actorId->toRfc4122()] ?? null;
                $freshApplicant = $lockedUsers[$applicantId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User || !$freshApplicant instanceof User) {
                    throw OnboardingApplicationException::notFound();
                }
                if ($freshActor->getId()->equals($freshApplicant->getId())) {
                    throw InvalidUserTransitionException::management(
                        UserManagementFailureReason::SelfManagementForbidden,
                        'Actors cannot change their own global roles.',
                    );
                }
                if (!$this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($freshActor)) {
                    throw OnboardingApplicationException::unauthorized();
                }

                $locked = $this->applications->findOneForUserForUpdate($freshApplicant, $applicationId);
                if (null === $locked) {
                    throw OnboardingApplicationException::notFound();
                }
                if (OnboardingApplicationStatus::Approved !== $locked->getStatus()) {
                    throw OnboardingApplicationException::invalidTransition();
                }
                if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($freshApplicant)) {
                    throw OnboardingApplicationException::applicantNotEligible();
                }
                if ($this->holdsTeacherPrivilege($freshApplicant)) {
                    return false;
                }

                $this->roles->addRole($freshApplicant, UserRole::Teacher, $freshActor, $reason);

                return true;
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw InvalidUserTransitionException::management(
                UserManagementFailureReason::Conflict,
                'User role mutation could not complete due to a lock conflict. Retry later.',
            );
        }
    }

    private function reasonFor(Uuid $applicationId): string
    {
        $reason = self::REASON_PREFIX.$applicationId->toRfc4122();
        if (\strlen($reason) > 255) {
            throw InvalidUserTransitionException::forRole('Teacher application role reason exceeds the role service limit.');
        }

        return $reason;
    }

    private function holdsTeacherPrivilege(User $user): bool
    {
        $roles = $user->getRoles();

        return \in_array(UserRole::Teacher->value, $roles, true)
            || \in_array(UserRole::ExpertTeacher->value, $roles, true)
            || \in_array(UserRole::HeadTeacher->value, $roles, true);
    }
}

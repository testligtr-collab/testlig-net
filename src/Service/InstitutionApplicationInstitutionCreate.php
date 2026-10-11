<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\OnboardingApplicationStatus;
use App\Exception\InstitutionOperationException;
use App\Exception\OnboardingApplicationException;
use App\Repository\InstitutionApplicationRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates a pending institution from an approved application and links it.
 * Approval itself never creates the institution.
 */
final class InstitutionApplicationInstitutionCreate
{
    private const REASON_PREFIX = 'institution_application_';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionApplicationRepository $applications,
        private readonly InstitutionCreator $institutionCreator,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(User $actor, Uuid $applicationId): bool
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
                $lockedUsers = $this->freshEntities->findFreshLockedUsers(
                    [$actorId, $applicantId],
                    LockMode::PESSIMISTIC_WRITE,
                );
                $freshActor = $lockedUsers[$actorId->toRfc4122()] ?? null;
                $freshApplicant = $lockedUsers[$applicantId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User || !$freshApplicant instanceof User) {
                    throw OnboardingApplicationException::notFound();
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
                if (null !== $locked->getCreatedInstitution()) {
                    return false;
                }

                $institution = $this->institutionCreator->create(
                    $freshActor,
                    $freshApplicant,
                    $locked->getProposedName(),
                    $locked->getProposedType(),
                    $reason,
                );
                $locked->linkCreatedInstitution($institution, \DateTimeImmutable::createFromInterface($this->clock->now()));
                $this->entityManager->flush();

                return true;
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw InstitutionOperationException::conflict();
        }
    }

    private function reasonFor(Uuid $applicationId): string
    {
        $reason = self::REASON_PREFIX.str_replace('-', '', $applicationId->toRfc4122());
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reason)) {
            throw InstitutionOperationException::invalidInput('reason_code must be snake_case.');
        }

        return $reason;
    }
}

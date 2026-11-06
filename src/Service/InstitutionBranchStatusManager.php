<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\Institution;
use App\Entity\InstitutionBranch;
use App\Entity\User;
use App\Enum\InstitutionBranchStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\InstitutionOperationException;
use App\Repository\InstitutionBranchRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Branch status transitions. Activation also requires the parent institution to be active.
 *
 * Lock order: branch (WRITE) → institution (READ) → actor (READ).
 */
final class InstitutionBranchStatusManager
{
    public function __construct(
        private readonly InstitutionBranchRepository $branches,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function activate(Uuid $branchId, User $actor, string $reasonCode): void
    {
        $this->transition($branchId, InstitutionBranchStatus::Active, $actor, $reasonCode);
    }

    public function suspend(Uuid $branchId, User $actor, string $reasonCode): void
    {
        $this->transition($branchId, InstitutionBranchStatus::Suspended, $actor, $reasonCode);
    }

    public function archive(Uuid $branchId, User $actor, string $reasonCode): void
    {
        $this->transition($branchId, InstitutionBranchStatus::Archived, $actor, $reasonCode);
    }

    private function transition(Uuid $branchId, InstitutionBranchStatus $target, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use ($branchId, $target, $actorId, $reasonCode): void {
                $branch = $this->freshEntities->findFreshLockedInstitutionBranch($branchId, LockMode::PESSIMISTIC_WRITE);
                if (!$branch instanceof InstitutionBranch) {
                    throw InstitutionOperationException::notFound();
                }
                $institution = $this->freshEntities->findFreshLockedInstitution($branch->getInstitution()->getId(), LockMode::PESSIMISTIC_READ);
                if (!$institution instanceof Institution) {
                    throw InstitutionOperationException::notFound();
                }
                $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$freshActor instanceof User) {
                    throw InstitutionOperationException::userNotFound();
                }
                if (!$this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($freshActor)) {
                    throw InstitutionOperationException::unauthorized();
                }
                if (InstitutionBranchStatus::Active === $target && InstitutionStatus::Active !== $institution->getStatus()) {
                    throw InstitutionOperationException::institutionNotActive();
                }

                $previous = $branch->getStatus();
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $branch->transitionTo($target, $now);
                $this->branches->save($branch, false);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::InstitutionBranchStatusChanged,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'institution_branch_status_manager',
                        'reason_code' => $reasonCode,
                        'institution_id' => $institution->getId()->toRfc4122(),
                        'branch_id' => $branch->getId()->toRfc4122(),
                        'institution_status' => $institution->getStatus()->value,
                        'previous_status' => $previous->value,
                        'new_status' => $target->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw InstitutionOperationException::conflict();
        }
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw InstitutionOperationException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}

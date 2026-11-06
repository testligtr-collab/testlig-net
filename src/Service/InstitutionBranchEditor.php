<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\InstitutionBranch;
use App\Entity\User;
use App\Enum\InstitutionBranchKind;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\InstitutionOperationException;
use App\Repository\InstitutionBranchRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Edits name, code, and kind. The institution foreign key is not writable.
 *
 * Lock order: branch (WRITE) → actor (READ).
 */
final class InstitutionBranchEditor
{
    public function __construct(
        private readonly InstitutionBranchRepository $branches,
        private readonly InstitutionNameNormalizer $names,
        private readonly InstitutionBranchCodeNormalizer $codes,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function update(
        Uuid $branchId,
        User $actor,
        string $name,
        string $code,
        InstitutionBranchKind $kind,
        string $reasonCode,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $normalizedName = $this->names->normalize($name);
        $normalizedCode = $this->codes->normalize($code);
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use ($branchId, $actorId, $normalizedName, $normalizedCode, $kind, $reasonCode): void {
                $branch = $this->freshEntities->findFreshLockedInstitutionBranch($branchId, LockMode::PESSIMISTIC_WRITE);
                if (!$branch instanceof InstitutionBranch) {
                    throw InstitutionOperationException::notFound();
                }
                $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$freshActor instanceof User) {
                    throw InstitutionOperationException::userNotFound();
                }
                if (!$this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($freshActor)) {
                    throw InstitutionOperationException::unauthorized();
                }
                $institutionId = $branch->getInstitution()->getId();
                if ($this->branches->existsWithCode($institutionId, $normalizedCode['normalizedCode'], $branch->getId())) {
                    throw InstitutionOperationException::conflict();
                }

                $previousKind = $branch->getKind()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $branch->revise(
                    $normalizedName['name'],
                    $normalizedName['normalizedName'],
                    $normalizedCode['code'],
                    $normalizedCode['normalizedCode'],
                    $kind,
                    $now,
                );
                $this->branches->save($branch, false);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::InstitutionBranchUpdated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'institution_branch_editor',
                        'reason_code' => $reasonCode,
                        'institution_id' => $institutionId->toRfc4122(),
                        'branch_id' => $branch->getId()->toRfc4122(),
                        'previous_kind' => $previousKind,
                        'branch_kind' => $kind->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw InstitutionOperationException::conflict();
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

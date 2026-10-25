<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\Institution;
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
 * Creates a pending branch for one existing institution. SUPER_ADMIN only.
 *
 * Lock order: institution (WRITE) → actor (READ). No membership or role is written.
 */
final class InstitutionBranchCreator
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

    public function create(
        User $actor,
        Uuid $institutionId,
        string $name,
        string $code,
        InstitutionBranchKind $kind,
        string $reasonCode,
    ): InstitutionBranch {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $normalizedName = $this->names->normalize($name);
        $normalizedCode = $this->codes->normalize($code);
        $actorId = $actor->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use ($actorId, $institutionId, $normalizedName, $normalizedCode, $kind, $reasonCode): InstitutionBranch {
                $institution = $this->freshEntities->findFreshLockedInstitution($institutionId, LockMode::PESSIMISTIC_WRITE);
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
                if ($this->branches->existsWithCode($institution->getId(), $normalizedCode['normalizedCode'])) {
                    throw InstitutionOperationException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $branch = InstitutionBranch::create(
                    institution: $institution,
                    name: $normalizedName['name'],
                    normalizedName: $normalizedName['normalizedName'],
                    code: $normalizedCode['code'],
                    normalizedCode: $normalizedCode['normalizedCode'],
                    kind: $kind,
                    now: $now,
                );
                $this->branches->save($branch, false);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::InstitutionBranchCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'institution_branch_creator',
                        'reason_code' => $reasonCode,
                        'institution_id' => $institution->getId()->toRfc4122(),
                        'branch_id' => $branch->getId()->toRfc4122(),
                        'branch_kind' => $kind->value,
                        'new_status' => $branch->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();

                return $branch;
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

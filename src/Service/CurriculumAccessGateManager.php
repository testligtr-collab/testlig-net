<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\CurriculumAccessGate;
use App\Entity\User;
use App\Enum\CurriculumOperation;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CurriculumException;
use App\Repository\CurriculumAccessGateRepository;
use App\Security\CurriculumActorPolicy;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Toggles the fixed stage and grade gates. It does not create or delete keys.
 */
final class CurriculumAccessGateManager
{
    public function __construct(
        private readonly CurriculumAccessGateRepository $gates,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly CurriculumActorPolicy $actorPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function deactivate(string $kind, string $gateKey, User $actor, string $reasonCode): void
    {
        $this->change($kind, $gateKey, $actor, $reasonCode, false);
    }

    public function reactivate(string $kind, string $gateKey, User $actor, string $reasonCode): void
    {
        $this->change($kind, $gateKey, $actor, $reasonCode, true);
    }

    public function rename(string $kind, string $gateKey, User $actor, string $label, string $reasonCode): void
    {
        $reasonCode = $this->reason($reasonCode);
        $label = $this->label($label);
        $rejected = null;
        try {
            $this->entityManager->wrapInTransaction(function () use ($kind, $gateKey, $actor, $label, $reasonCode, &$rejected): void {
                try {
                    $locked = $this->lock($kind, $gateKey);
                    $freshActor = $this->lockActor($actor->getId(), CurriculumOperation::Manage);
                    $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                    $locked->rename($label, $now);
                    $this->gates->save($locked, false);
                    $this->record($locked, $freshActor, $reasonCode, $locked->getStatus()->value, $locked->getStatus()->value);
                    $this->entityManager->flush();
                } catch (CurriculumException $exception) {
                    $rejected = $exception;
                }
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CurriculumException::conflict();
        }
        if ($rejected instanceof CurriculumException) {
            throw $rejected;
        }
    }

    private function change(string $kind, string $gateKey, User $actor, string $reasonCode, bool $active): void
    {
        $reasonCode = $this->reason($reasonCode);
        $rejected = null;
        try {
            $this->entityManager->wrapInTransaction(function () use ($kind, $gateKey, $actor, $reasonCode, $active, &$rejected): void {
                try {
                    $locked = $this->lock($kind, $gateKey);
                    $freshActor = $this->lockActor($actor->getId(), CurriculumOperation::Availability);
                    $old = $locked->getStatus()->value;
                    $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                    if ($active) {
                        $locked->reactivate($now);
                    } else {
                        $locked->deactivate($now);
                    }
                    $this->gates->save($locked, false);
                    $this->record($locked, $freshActor, $reasonCode, $old, $locked->getStatus()->value);
                    $this->entityManager->flush();
                } catch (CurriculumException $exception) {
                    $rejected = $exception;
                }
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CurriculumException::conflict();
        }
        if ($rejected instanceof CurriculumException) {
            throw $rejected;
        }
    }

    private function lock(string $kind, string $gateKey): CurriculumAccessGate
    {
        if (!\in_array($kind, ['stage', 'grade'], true)) {
            throw CurriculumException::notFound();
        }
        $gate = $this->gates->findOne($kind, $gateKey);
        if (!$gate instanceof CurriculumAccessGate) {
            throw CurriculumException::notFound();
        }
        $locked = $this->freshEntities->findFreshLockedCurriculumAccessGate($gate->getId(), LockMode::PESSIMISTIC_WRITE);
        if (!$locked instanceof CurriculumAccessGate) {
            throw CurriculumException::notFound();
        }

        return $locked;
    }

    private function lockActor(Uuid $actorId, CurriculumOperation $operation): User
    {
        $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
        if (!$freshActor instanceof User) {
            throw CurriculumException::notFound();
        }
        if (!$this->actorPolicy->allowsFresh($freshActor, $operation)) {
            throw CurriculumException::unauthorized();
        }

        return $freshActor;
    }

    private function record(CurriculumAccessGate $gate, User $actor, string $reasonCode, string $old, string $new): void
    {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::CurriculumAccessGateUpdated,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: [
                'source' => 'curriculum_access_gate_manager',
                'reason_code' => $reasonCode,
                'target_type' => $gate->getKind(),
                'resource_id' => $gate->getId()->toRfc4122(),
                'code' => $gate->getGateKey(),
                'old_status' => $old,
                'new_status' => $new,
            ],
            captureRequestHashes: false,
        ), false);
    }

    private function reason(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw CurriculumException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }

    private function label(string $label): string
    {
        $label = trim($label);
        $length = mb_strlen($label);
        if ($length < 2 || $length > 80) {
            throw CurriculumException::invalidInput('Gate label length is invalid.');
        }

        return $label;
    }
}

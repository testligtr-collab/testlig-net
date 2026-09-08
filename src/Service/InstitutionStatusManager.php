<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Exception\InstitutionOperationException;
use App\Repository\InstitutionRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Controlled institution status transitions. SUPER_ADMIN only in this stage.
 */
final class InstitutionStatusManager
{
    public function __construct(
        private readonly InstitutionRepository $institutions,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function activate(Institution $institution, User $actor, string $reasonCode): void
    {
        $this->transition($institution, InstitutionStatus::Active, $actor, $reasonCode);
    }

    public function suspend(Institution $institution, User $actor, string $reasonCode): void
    {
        $this->transition($institution, InstitutionStatus::Suspended, $actor, $reasonCode);
    }

    public function archive(Institution $institution, User $actor, string $reasonCode): void
    {
        $this->transition($institution, InstitutionStatus::Archived, $actor, $reasonCode);
    }

    private function transition(Institution $institution, InstitutionStatus $target, User $actor, string $reasonCode): void
    {
        $this->assertSuperAdmin($actor);
        $reasonCode = $this->normalizeReasonCode($reasonCode);

        $this->entityManager->wrapInTransaction(function () use ($institution, $target, $actor, $reasonCode): void {
            $locked = $this->entityManager->find(Institution::class, $institution->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$locked instanceof Institution) {
                throw InstitutionOperationException::notFound();
            }

            $previous = $locked->getStatus();
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $locked->transitionTo($target, $now);
            $this->institutions->save($locked, false);

            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::InstitutionStatusChanged,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $actor,
                metadata: [
                    'source' => 'institution_status_manager',
                    'reason_code' => $reasonCode,
                    'institution_id' => $locked->getId()->toRfc4122(),
                    'institution_type' => $locked->getType()->value,
                    'previous_status' => $previous->value,
                    'new_status' => $target->value,
                ],
                captureRequestHashes: false,
            ), false);

            $this->entityManager->flush();
        });
    }

    private function assertSuperAdmin(User $actor): void
    {
        if (!\in_array(UserRole::SuperAdmin->value, $actor->getRoles(), true)) {
            throw InstitutionOperationException::unauthorized();
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

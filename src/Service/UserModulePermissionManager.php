<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\User;
use App\Enum\ModeratorModuleGrant;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Exception\UserModulePermissionException;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Replaces one moderator's direct module grants.
 *
 * The actor and target are reloaded under lock. The form version must match.
 * Role rows and protected permissions are never written.
 */
final class UserModulePermissionManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FreshUserLoader $freshUsers,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<mixed> $requestedCodes
     */
    public function replace(Uuid $actorId, Uuid $targetId, int $expectedVersion, array $requestedCodes): bool
    {
        if ($expectedVersion < 0) {
            throw UserModulePermissionException::invalid();
        }
        $desired = $this->normalize($requestedCodes);
        $changed = false;
        $this->entityManager->wrapInTransaction(function () use ($actorId, $targetId, $expectedVersion, $desired, &$changed): void {
            $users = $this->freshUsers->findFreshLockedUsers([$actorId, $targetId], LockMode::PESSIMISTIC_READ);
            $actor = $users[$actorId->toRfc4122()] ?? null;
            $target = $users[$targetId->toRfc4122()] ?? null;
            if (!$actor instanceof User || !$this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($actor)) {
                throw UserModulePermissionException::unauthorized();
            }
            if (!$target instanceof User || !$this->isEligible($target)) {
                throw UserModulePermissionException::ineligible();
            }

            $binary = $target->getId()->toBinary();
            $connection = $this->entityManager->getConnection();
            $state = $connection->fetchAssociative(
                'SELECT version FROM user_module_permission_states WHERE user_id = :id FOR UPDATE',
                ['id' => $binary],
                ['id' => ParameterType::BINARY],
            );
            $version = false === $state ? 0 : (int) $state['version'];
            if ($version !== $expectedVersion) {
                throw UserModulePermissionException::conflict();
            }

            $current = $this->currentCodes($binary);
            if ($current === $desired) {
                return;
            }
            $added = array_values(array_diff($desired, $current));
            $removed = array_values(array_diff($current, $desired));
            sort($added);
            sort($removed);

            try {
                foreach ($removed as $code) {
                    $connection->executeStatement(
                        'DELETE FROM user_module_permissions WHERE user_id = :id AND permission_code = :code',
                        ['id' => $binary, 'code' => $code],
                        ['id' => ParameterType::BINARY],
                    );
                }
                foreach ($added as $code) {
                    $id = new UuidV7();
                    $connection->executeStatement(
                        'INSERT INTO user_module_permissions (id, user_id, permission_code) VALUES (:id, :user, :code)',
                        ['id' => $id->toBinary(), 'user' => $binary, 'code' => $code],
                        ['id' => ParameterType::BINARY, 'user' => ParameterType::BINARY],
                    );
                }
                $now = $this->clock->now()->format('Y-m-d H:i:s');
                if (false === $state) {
                    $connection->executeStatement(
                        'INSERT INTO user_module_permission_states (user_id, version, updated_at) VALUES (:id, 1, :updated)',
                        ['id' => $binary, 'updated' => $now],
                        ['id' => ParameterType::BINARY],
                    );
                } else {
                    $updated = $connection->executeStatement(
                        'UPDATE user_module_permission_states SET version = version + 1, updated_at = :updated WHERE user_id = :id AND version = :version',
                        ['updated' => $now, 'id' => $binary, 'version' => $expectedVersion],
                        ['id' => ParameterType::BINARY],
                    );
                    if (1 !== $updated) {
                        throw UserModulePermissionException::conflict();
                    }
                }
            } catch (UniqueConstraintViolationException) {
                throw UserModulePermissionException::conflict();
            }

            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::ModulePermissionChanged,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $actor,
                subjectUser: $target,
                metadata: [
                    'added_permissions' => $added,
                    'removed_permissions' => $removed,
                    'version' => $expectedVersion + 1,
                ],
            ));
            $changed = true;
        });
        $this->authCache->invalidateUser($targetId);

        return $changed;
    }

    /**
     * @param array<mixed> $requestedCodes
     *
     * @return list<string>
     */
    private function normalize(array $requestedCodes): array
    {
        $desired = [];
        foreach ($requestedCodes as $code) {
            if (!\is_string($code)) {
                throw UserModulePermissionException::invalid();
            }
            $grant = ModeratorModuleGrant::tryFrom($code);
            if (!$grant instanceof ModeratorModuleGrant) {
                throw UserModulePermissionException::invalid();
            }
            $desired[] = $grant->value;
        }
        $desired = array_values(array_unique($desired));
        sort($desired);

        return $desired;
    }

    private function isEligible(User $user): bool
    {
        $values = [];
        foreach ($user->getGlobalRoleEnums() as $role) {
            $values[] = $role->value;
        }

        return \in_array(UserRole::Moderator->value, $values, true)
            && !\in_array(UserRole::Admin->value, $values, true)
            && !\in_array(UserRole::SuperAdmin->value, $values, true);
    }

    /**
     * @return list<string>
     */
    private function currentCodes(string $binaryUserId): array
    {
        $rows = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT permission_code FROM user_module_permissions WHERE user_id = :id',
            ['id' => $binaryUserId],
            ['id' => ParameterType::BINARY],
        );
        $codes = [];
        foreach ($rows as $code) {
            if (\is_string($code) && null !== ModeratorModuleGrant::tryFrom($code)) {
                $codes[] = $code;
            }
        }
        sort($codes);

        return $codes;
    }
}

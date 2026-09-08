<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\UserStatus;
use App\Security\Authorization\InstitutionAuthorizationSnapshot;
use App\Security\Authorization\MembershipAuthorizationSnapshot;
use App\Security\Authorization\UserAuthorizationSnapshot;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped DB authorization snapshots for InstitutionVoter.
 *
 * Reads scalar projections via DBAL — never returns Doctrine-managed User/Institution/
 * Membership entities, so identity-map staleness cannot affect voter decisions.
 * Cache is per request and cleared via {@see ResetInterface::reset()}.
 * Mid-request mutations are not invalidated automatically; HTTP flows vote before mutate.
 */
final class RequestScopedInstitutionAuthLookup implements ResetInterface
{
    /** @var array<string, UserAuthorizationSnapshot|null> */
    private array $users = [];

    /** @var array<string, InstitutionAuthorizationSnapshot|null> */
    private array $institutions = [];

    /** @var array<string, MembershipAuthorizationSnapshot|null> */
    private array $memberships = [];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getUserSnapshot(Uuid $userId): ?UserAuthorizationSnapshot
    {
        $key = $userId->toRfc4122();
        if (!\array_key_exists($key, $this->users)) {
            $this->users[$key] = $this->fetchUserSnapshot($userId);
        }

        return $this->users[$key];
    }

    public function getInstitutionSnapshot(Uuid $institutionId): ?InstitutionAuthorizationSnapshot
    {
        $key = $institutionId->toRfc4122();
        if (!\array_key_exists($key, $this->institutions)) {
            $this->institutions[$key] = $this->fetchInstitutionSnapshot($institutionId);
        }

        return $this->institutions[$key];
    }

    public function getMembershipSnapshot(Uuid $userId, Uuid $institutionId): ?MembershipAuthorizationSnapshot
    {
        $key = $userId->toRfc4122().'|'.$institutionId->toRfc4122();
        if (!\array_key_exists($key, $this->memberships)) {
            $this->memberships[$key] = $this->fetchMembershipSnapshot($userId, $institutionId);
        }

        return $this->memberships[$key];
    }

    public function reset(): void
    {
        $this->users = [];
        $this->institutions = [];
        $this->memberships = [];
    }

    private function fetchUserSnapshot(Uuid $userId): ?UserAuthorizationSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, status, email_verified_at, global_roles FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId->toBinary()],
        );
        if (false === $row) {
            return null;
        }

        $decoded = json_decode((string) $row['global_roles'], true, 512, \JSON_THROW_ON_ERROR);
        $roles = [];
        if (\is_array($decoded)) {
            foreach ($decoded as $role) {
                if (\is_string($role) && '' !== $role) {
                    $roles[] = $role;
                }
            }
        }
        $roles = array_values(array_unique($roles));
        if (!\in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }
        sort($roles);

        return new UserAuthorizationSnapshot(
            id: $this->uuidFromBinary($row['id']),
            status: UserStatus::from((string) $row['status']),
            emailVerified: null !== $row['email_verified_at'],
            roles: $roles,
        );
    }

    private function fetchInstitutionSnapshot(Uuid $institutionId): ?InstitutionAuthorizationSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, status FROM institutions WHERE id = :id LIMIT 1',
            ['id' => $institutionId->toBinary()],
        );
        if (false === $row) {
            return null;
        }

        return new InstitutionAuthorizationSnapshot(
            id: $this->uuidFromBinary($row['id']),
            status: InstitutionStatus::from((string) $row['status']),
        );
    }

    private function fetchMembershipSnapshot(Uuid $userId, Uuid $institutionId): ?MembershipAuthorizationSnapshot
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, institution_id, user_id, role, status
             FROM institution_memberships
             WHERE user_id = :userId AND institution_id = :institutionId
             LIMIT 1',
            [
                'userId' => $userId->toBinary(),
                'institutionId' => $institutionId->toBinary(),
            ],
        );
        if (false === $row) {
            return null;
        }

        return new MembershipAuthorizationSnapshot(
            id: $this->uuidFromBinary($row['id']),
            institutionId: $this->uuidFromBinary($row['institution_id']),
            userId: $this->uuidFromBinary($row['user_id']),
            role: InstitutionMembershipRole::from((string) $row['role']),
            status: InstitutionMembershipStatus::from((string) $row['status']),
        );
    }

    private function uuidFromBinary(mixed $binary): Uuid
    {
        return Uuid::fromBinary((string) $binary);
    }
}

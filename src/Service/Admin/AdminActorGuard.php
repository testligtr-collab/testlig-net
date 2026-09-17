<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use App\Exception\CommerceException;
use App\Security\AdminAuthorization;
use App\Service\FreshUserLoader;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Fresh-lock actor resolution shared by admin read/write services.
 */
final class AdminActorGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FreshUserLoader $freshUsers,
        private readonly AdminAuthorization $adminAuthorization,
    ) {
    }

    /**
     * @param callable(User): void $assert
     */
    public function withFreshActor(Uuid $actorId, callable $assert): User
    {
        return $this->em->wrapInTransaction(function () use ($actorId, $assert): User {
            $actor = $this->freshUsers->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
            if (!$actor instanceof User) {
                throw CommerceException::unauthorized();
            }
            $assert($actor);

            return $actor;
        });
    }

    public function requireShell(Uuid $actorId): User
    {
        return $this->withFreshActor($actorId, $this->adminAuthorization->assertCanAccessAdminShell(...));
    }

    public function requireSystemView(Uuid $actorId): User
    {
        return $this->withFreshActor($actorId, $this->adminAuthorization->assertCanViewSystemSummary(...));
    }

    public function requirePaymentOps(Uuid $actorId): User
    {
        return $this->withFreshActor($actorId, $this->adminAuthorization->assertCanOperatePayments(...));
    }

    public function requireAuditView(Uuid $actorId): User
    {
        return $this->withFreshActor($actorId, $this->adminAuthorization->assertCanViewSecurityAudit(...));
    }

    public function requireUsersView(Uuid $actorId): User
    {
        return $this->withFreshActor($actorId, $this->adminAuthorization->assertCanViewUsers(...));
    }

    public function requireUsersManage(Uuid $actorId): User
    {
        return $this->withFreshActor($actorId, $this->adminAuthorization->assertCanManageUsers(...));
    }

    public function requireInstitutionsView(Uuid $actorId): User
    {
        return $this->withFreshActor($actorId, $this->adminAuthorization->assertCanViewInstitutions(...));
    }

    public function requireInstitutionsCreate(Uuid $actorId): User
    {
        return $this->withFreshActor($actorId, $this->adminAuthorization->assertCanCreateInstitutions(...));
    }

    public function requireInstitutionsManage(Uuid $actorId): User
    {
        return $this->withFreshActor($actorId, $this->adminAuthorization->assertCanManageInstitutions(...));
    }

    public function requireMembershipsView(Uuid $actorId): User
    {
        return $this->withFreshActor($actorId, $this->adminAuthorization->assertCanViewMemberships(...));
    }

    public function requireMembershipsManage(Uuid $actorId): User
    {
        return $this->withFreshActor($actorId, $this->adminAuthorization->assertCanManageMemberships(...));
    }
}

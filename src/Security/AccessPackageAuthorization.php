<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\UserRole;
use App\Exception\AccessEntitlementException;
use App\Service\ActiveVerifiedUserPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Authorization checks for access package / license / seat mutations.
 *
 * - SUPER_ADMIN: full package + license management
 * - HEAD/EXPERT: prepare draft packages/versions/grants and resource policies; cannot activate commercial packages/versions
 * - Institution Owner: manage institution licenses + seats for own tenant
 * - Institution Manager: seats only for own tenant
 * - Global ROLE_INSTITUTION_MANAGER alone never grants tenant access
 */
final class AccessPackageAuthorization
{
    public function __construct(
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function assertCanPreparePackages(User $actor): void
    {
        $this->assertActiveVerified($actor);
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }
        if ($this->hasAnyRole($actor, [UserRole::HeadTeacher, UserRole::ExpertTeacher])) {
            return;
        }

        throw AccessEntitlementException::unauthorized();
    }

    public function assertCanActivatePackages(User $actor): void
    {
        $this->assertActiveVerified($actor);
        if (!$this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            throw AccessEntitlementException::unauthorized();
        }
    }

    public function assertCanManageUserLicenses(User $actor): void
    {
        $this->assertCanActivatePackages($actor);
    }

    public function assertCanManageInstitutionLicenses(User $actor, Institution $institution): void
    {
        $this->assertActiveVerified($actor);
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }
        $membership = $this->findActiveMembership($actor, $institution);
        if ($membership instanceof InstitutionMembership
            && InstitutionMembershipRole::Owner === $membership->getRole()
        ) {
            return;
        }

        throw AccessEntitlementException::unauthorized();
    }

    public function assertCanManageSeats(User $actor, Institution $institution): void
    {
        $this->assertActiveVerified($actor);
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }
        $membership = $this->findActiveMembership($actor, $institution);
        if ($membership instanceof InstitutionMembership
            && \in_array($membership->getRole(), [
                InstitutionMembershipRole::Owner,
                InstitutionMembershipRole::Manager,
            ], true)
        ) {
            return;
        }

        throw AccessEntitlementException::unauthorized();
    }

    public function assertCanSetResourceAccessPolicy(User $actor): void
    {
        $this->assertCanPreparePackages($actor);
    }

    private function assertActiveVerified(User $actor): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw AccessEntitlementException::unauthorized();
        }
    }

    /**
     * @param list<UserRole> $roles
     */
    private function hasAnyRole(User $actor, array $roles): bool
    {
        foreach ($roles as $role) {
            if (\in_array($role->value, $actor->getRoles(), true)) {
                return true;
            }
        }

        return false;
    }

    private function findActiveMembership(User $user, Institution $institution): ?InstitutionMembership
    {
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            return null;
        }

        $membership = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(InstitutionMembership::class, 'm')
            ->andWhere('m.user = :user')
            ->andWhere('m.institution = :institution')
            ->andWhere('m.status = :status')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('status', InstitutionMembershipStatus::Active)
            ->setMaxResults(1)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $membership instanceof InstitutionMembership ? $membership : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\CommerceOrder;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\CommercePurchaserType;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Exception\CommerceException;
use App\Service\ActiveVerifiedUserPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Authorization for commerce catalog, purchase, and settlement mutations.
 *
 * Mirrors {@see AccessPackageAuthorization} but is deliberately stricter about proxying:
 *
 * - **Catalog** (offer create/update/activate/retire): active+verified SUPER_ADMIN only.
 * - **Individual purchase**: the buyer themselves, active+verified. ADMIN / MODERATOR /
 *   SUPER_ADMIN cannot start a payment "on behalf of" a user — there is no silent
 *   ownership bypass, because a purchase creates a paid entitlement for a real person.
 * - **Institution purchase**: institution Owner with an active membership in an active
 *   institution. Managers keep seat-only powers from Stage 2.16 and cannot start payments;
 *   teacher / staff / student are denied. A global ROLE_INSTITUTION_MANAGER alone never
 *   grants tenant access.
 * - **Settlement / fulfillment / refund**: active+verified SUPER_ADMIN acting as the
 *   platform settlement operator. This matches AccessLicenseManager, which requires
 *   SUPER_ADMIN to mint user licenses.
 */
final class CommerceAuthorization
{
    public function __construct(
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function assertCanManageCatalog(User $actor): void
    {
        $this->assertActiveVerified($actor);
        if (!$this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            throw CommerceException::unauthorized();
        }
    }

    public function assertCanSettlePayments(User $actor): void
    {
        $this->assertCanManageCatalog($actor);
    }

    /**
     * Payment operations / reconciliation / dead-letter requeue: same bar as settlement.
     */
    public function assertCanOperatePayments(User $actor): void
    {
        $this->assertCanSettlePayments($actor);
    }

    /**
     * Individual purchases: actor must be the purchaser. No privileged proxy purchase.
     */
    public function assertCanPurchaseForSelf(User $actor, User $purchaser): void
    {
        $this->assertActiveVerified($actor);
        if (!$actor->getId()->equals($purchaser->getId())) {
            throw CommerceException::unauthorized();
        }
        $this->assertActiveVerified($purchaser);
    }

    public function assertCanManageInstitutionCommerce(User $actor, Institution $institution): void
    {
        $this->assertActiveVerified($actor);
        $membership = $this->findActiveMembership($actor, $institution);
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipRole::Owner !== $membership->getRole()
        ) {
            throw CommerceException::unauthorized();
        }
    }

    /**
     * Gate for order creation / payment start, resolved from the order's purchaser pair.
     */
    public function assertCanManageOrder(User $actor, CommerceOrder $order): void
    {
        if (CommercePurchaserType::User === $order->getPurchaserType()) {
            $purchaser = $order->getUser();
            if (!$purchaser instanceof User) {
                throw CommerceException::invalidInput('User order requires a purchaser user.');
            }
            $this->assertCanPurchaseForSelf($actor, $purchaser);

            return;
        }

        $institution = $order->getInstitution();
        if (!$institution instanceof Institution) {
            throw CommerceException::invalidInput('Institution order requires a purchaser institution.');
        }
        $this->assertCanManageInstitutionCommerce($actor, $institution);
    }

    private function assertActiveVerified(User $user): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($user)) {
            throw CommerceException::unauthorized();
        }
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

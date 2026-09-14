<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\CommerceException;
use App\Repository\UserRepository;
use App\Security\CommerceAuthorization;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Uid\Uuid;

/**
 * Loads the platform SUPER_ADMIN used to apply provider settlement from checkout/webhooks.
 *
 * Buyer actors never settle; provider outcomes are recorded under this operator.
 */
final class PaymentPlatformSettlementActorResolver
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly CommerceAuthorization $authorization,
        private readonly string $settlementActorId,
        private readonly ?PaymentPlatformSettlementActorOverride $override = null,
    ) {
    }

    public function resolve(): User
    {
        if ($this->override instanceof PaymentPlatformSettlementActorOverride
            && $this->override->getActor() instanceof User
        ) {
            $actor = $this->override->getActor();
            $this->authorization->assertCanSettlePayments($actor);

            return $actor;
        }

        $id = trim($this->settlementActorId);
        if ('' === $id) {
            throw CommerceException::invalidInput('PAYMENT_PLATFORM_SETTLEMENT_ACTOR_ID is not configured.');
        }
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw CommerceException::invalidInput('PAYMENT_PLATFORM_SETTLEMENT_ACTOR_ID is invalid.');
        }

        $locked = $this->freshEntities->findFreshLockedUsers([$uuid], LockMode::PESSIMISTIC_READ);
        $actor = $locked[$uuid->toRfc4122()] ?? null;
        if (!$actor instanceof User) {
            $actor = $this->users->find($uuid);
        }
        if (!$actor instanceof User) {
            throw CommerceException::userNotFound();
        }
        $this->authorization->assertCanSettlePayments($actor);

        return $actor;
    }
}

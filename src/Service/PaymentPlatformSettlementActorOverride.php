<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * Test-only override for the platform settlement actor (wired under when@test).
 */
final class PaymentPlatformSettlementActorOverride
{
    private ?User $actor = null;

    public function setActor(?User $actor): void
    {
        $this->actor = $actor;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }
}

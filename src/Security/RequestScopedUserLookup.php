<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped cache for fresh User rows used by authorization voters.
 *
 * Avoids N+1 when multiple institution votes run for the same token user in one request.
 * No DB locks — read-only authorization path. Implements ResetInterface so the cache
 * clears between HTTP requests (and can be reset in tests after out-of-band DB updates).
 */
final class RequestScopedUserLookup implements ResetInterface
{
    /** @var array<string, User|null> */
    private array $byId = [];

    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function findCurrent(User $tokenUser): ?User
    {
        $key = $tokenUser->getId()->toRfc4122();
        if (!\array_key_exists($key, $this->byId)) {
            $this->byId[$key] = $this->users->findOneById($tokenUser->getId());
        }

        return $this->byId[$key];
    }

    public function reset(): void
    {
        $this->byId = [];
    }
}

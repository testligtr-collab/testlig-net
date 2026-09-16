<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\FreshUserLoader;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Menu / IsGranted gate for the admin operations panel.
 *
 * Reloads the actor via FreshUserLoader (PESSIMISTIC_READ) so stale session roles
 * cannot open payment/audit surfaces. Domain services still re-assert on every call.
 *
 * @extends Voter<string, null>
 */
final class AdminVoter extends Voter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FreshUserLoader $freshUsers,
        private readonly AdminAuthorization $adminAuthorization,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return null === $subject && \in_array($attribute, AdminPermission::all(), true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $tokenUser = $token->getUser();
        if (!$tokenUser instanceof User) {
            return false;
        }

        try {
            return $this->entityManager->wrapInTransaction(function () use ($tokenUser, $attribute): bool {
                $actor = $this->freshUsers->findFreshLockedUser($tokenUser->getId(), LockMode::PESSIMISTIC_READ);
                if (!$actor instanceof User) {
                    return false;
                }

                return match ($attribute) {
                    AdminPermission::ADMIN_SHELL_ACCESS => $this->adminAuthorization->canAccessAdminShell($actor),
                    AdminPermission::ADMIN_SYSTEM_VIEW => $this->adminAuthorization->canViewSystemSummary($actor),
                    AdminPermission::ADMIN_PAYMENT_OPS => $this->adminAuthorization->canOperatePayments($actor),
                    AdminPermission::ADMIN_AUDIT_VIEW => $this->adminAuthorization->canViewSecurityAudit($actor),
                    AdminPermission::ADMIN_DEAD_LETTER_REQUEUE => $this->adminAuthorization->canRequeueDeadLetter($actor),
                    default => false,
                };
            });
        } catch (\Throwable) {
            return false;
        }
    }
}

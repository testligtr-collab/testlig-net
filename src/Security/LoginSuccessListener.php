<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\UserAccountLifecycle;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Updates lastLoginAt after a successful authentication without failing the login.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final class LoginSuccessListener
{
    public function __construct(
        private readonly UserAccountLifecycle $accountLifecycle,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        try {
            $this->accountLifecycle->recordSuccessfulLogin($user);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to record last login timestamp.', [
                'user_id' => $user->getId()->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
        }
    }
}

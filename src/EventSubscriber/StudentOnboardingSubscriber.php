<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Enum\UserRole;
use App\Service\StudentProfileManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Keeps incomplete students on onboarding and completed students off the setup page.
 */
final class StudentOnboardingSubscriber implements EventSubscriberInterface
{
    private const ONBOARDING_ROUTE = 'app_student_onboarding';
    private const STUDENT_ROUTE_PREFIX = 'app_student_';

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly StudentProfileManager $profiles,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route) || !str_starts_with($route, self::STUDENT_ROUTE_PREFIX)) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        if (!$user instanceof User) {
            return;
        }

        if (!\in_array(UserRole::Student->value, $user->getRoles(), true)) {
            return;
        }

        $completed = $this->profiles->isOnboardingCompleted($user);

        if (self::ONBOARDING_ROUTE === $route) {
            if ($completed) {
                $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_student_dashboard')));
            }

            return;
        }

        if (!$completed) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate(self::ONBOARDING_ROUTE)));
        }
    }
}

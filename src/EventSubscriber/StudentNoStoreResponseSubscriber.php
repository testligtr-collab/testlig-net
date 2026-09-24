<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Student routes are actor/grade/entitlement-scoped — never store in shared caches.
 */
final class StudentNoStoreResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -1024]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if ('/ogrenci' !== $path && !str_starts_with($path, '/ogrenci/')) {
            return;
        }

        $event->getResponse()->headers->set('Cache-Control', 'no-store, private');
        $event->getResponse()->headers->set('Pragma', 'no-cache');
    }
}

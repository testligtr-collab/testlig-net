<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Release\ReleaseRevision;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tells the visitor which release answered: the full Git SHA of the release whose code produced the response.
 *
 * The value is a compiled container parameter of this release. Nothing is read from disk per request, and
 * nothing else (path, environment, user data, health detail) is exposed. Without a valid revision no header
 * is sent. Status, security headers and cache headers of the response are not touched.
 */
final class ReleaseRevisionHeaderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%app.release_revision%')]
        private readonly string $revision,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -2048]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || null === ReleaseRevision::parse($this->revision)) {
            return;
        }

        $event->getResponse()->headers->set(ReleaseRevision::HEADER, $this->revision);
    }
}

<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Completes missing response headers without changing the public SEO cache policy.
 *
 * Account, application, and token URLs are not stored or indexed.
 * Public registration forms stay indexable and are not cached, so a shared cache cannot replay a CSRF token.
 */
final class SensitiveResponseHeaderSubscriber implements EventSubscriberInterface
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

        $response = $event->getResponse();
        if (!$response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        $path = $event->getRequest()->getPathInfo();
        if ($this->isPrivate($path)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return;
        }

        if ($this->isPublicRegistrationForm($path)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }
    }

    private function isPrivate(string $path): bool
    {
        return '/hesabim' === $path || str_starts_with($path, '/hesabim/')
            || '/basvuru' === $path || str_starts_with($path, '/basvuru/')
            || '/sifre-yenile' === $path || str_starts_with($path, '/sifre-yenile/')
            || '/sifremi-unuttum' === $path || str_starts_with($path, '/sifremi-unuttum/')
            || '/dogrula/eposta' === $path || str_starts_with($path, '/dogrula/eposta/')
            || '/kayit/eposta-kontrol' === $path
            || '/kayit/dogrulama-yeniden' === $path;
    }

    private function isPublicRegistrationForm(string $path): bool
    {
        return '/kayit' === $path || 1 === preg_match('#^/kayit/(ogrenci|veli|ogretmen|kurum)$#', $path);
    }
}

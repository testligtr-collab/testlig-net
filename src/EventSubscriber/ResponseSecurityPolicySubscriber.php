<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\ContentSecurityPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Final HTML header policy. Runs after the path cache subscribers.
 *
 * Indexable routes are an explicit allowlist. Every other HTML response is noindex.
 * Symfony's debug-only DisallowRobotsIndexingListener stays disabled so / is not stamped in tests.
 */
final class ResponseSecurityPolicySubscriber implements EventSubscriberInterface
{
    public const NONCE_ATTRIBUTE = '_csp_nonce';

    /** @var list<string> */
    public const INDEXABLE_ROUTES = [
        'app_home',
        'app_legal_privacy',
        'app_legal_terms',
        'app_legal_cookies',
        'app_legal_children',
    ];

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 512],
            KernelEvents::RESPONSE => ['onResponse', -2048],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::NONCE_ATTRIBUTE, bin2hex(random_bytes(16)));
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $html = $this->isHtml($response);
        if (!$html && !$response->isRedirection()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->isIndexable($request, $response)) {
            $response->headers->remove('X-Robots-Tag');
        } else {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        $response->headers->set('X-Frame-Options', 'DENY');
        if (!$html) {
            return;
        }

        if (!$response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        $nonce = $request->attributes->get(self::NONCE_ATTRIBUTE);
        if (!\is_string($nonce) || 1 !== preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            $nonce = bin2hex(random_bytes(16));
        }

        $response->headers->set('Content-Security-Policy', ContentSecurityPolicy::header(
            $nonce,
            'prod' === $this->environment && $request->isSecure(),
        ));
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    }

    private function isIndexable(Request $request, Response $response): bool
    {
        $route = $request->attributes->get('_route');

        return $response->isSuccessful()
            && \is_string($route)
            && \in_array($route, self::INDEXABLE_ROUTES, true);
    }

    private function isHtml(Response $response): bool
    {
        $type = strtolower((string) $response->headers->get('Content-Type'));

        return str_starts_with($type, 'text/html');
    }
}

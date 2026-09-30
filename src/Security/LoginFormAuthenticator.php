<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailNormalizer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Form login with normalized-email identifier and safe local redirects only.
 */
final class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EmailNormalizer $emailNormalizer,
        private readonly UserRepository $users,
        private readonly PostLoginDestinationResolver $postLoginDestination,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $email = trim((string) $request->request->get('_username', ''));
        $password = (string) $request->request->get('_password', '');
        $csrfToken = (string) $request->request->get('_csrf_token', '');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        try {
            $normalized = $this->emailNormalizer->normalize($email);
        } catch (\InvalidArgumentException) {
            $normalized = mb_strtolower($email, 'UTF-8');
        }

        return new Passport(
            new UserBadge($normalized, function (string $userIdentifier): ?UserInterface {
                return $this->users->findOneByNormalizedEmail($userIdentifier);
            }),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
                new PasswordUpgradeBadge($password, $this->users),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): RedirectResponse
    {
        $target = $this->getTargetPath($request->getSession(), $firewallName);
        $this->removeTargetPath($request->getSession(), $firewallName);
        $user = $token->getUser();
        if ($user instanceof User) {
            return new RedirectResponse($this->postLoginDestination->resolve($user, $this->sameOriginPath($request, \is_string($target) ? $target : null))->location($this->urlGenerator));
        }

        return new RedirectResponse($this->urlGenerator->generate(PostLoginRoute::ACCOUNT_HOME));
    }

    /**
     * Symfony stores an absolute URI. Keep only a same-origin path.
     */
    private function sameOriginPath(Request $request, ?string $target): ?string
    {
        if (!\is_string($target) || '' === $target || str_contains($target, '\\')) {
            return null;
        }
        if (str_starts_with($target, '/') && !str_starts_with($target, '//')) {
            return $target;
        }

        $parts = parse_url($target);
        if (!\is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host || $scheme !== strtolower($request->getScheme()) || $host !== strtolower($request->getHost())) {
            return null;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== $request->getPort()) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '/');
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}

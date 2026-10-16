<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\WebRuntimeIdentityReader;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\Admin\AdminNavBuilder;
use App\Service\FreshUserLoader;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Default-off diagnostic of the PHP process that is serving this POST.
 *
 * The actor id comes from the session. The nonce correlates this response with
 * the POST body and does not replace CSRF.
 */
final class AdminWebRuntimeIdentityController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly bool $webRuntimeIdentityEnabled,
        private readonly EntityManagerInterface $entityManager,
        private readonly FreshUserLoader $freshUsers,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly WebRuntimeIdentityReader $reader,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/calisma-kimligi', name: 'app_admin_web_runtime_identity', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->webRuntimeIdentityEnabled) {
            throw $this->createNotFoundException();
        }

        $actor = $this->freshSuperAdmin();
        if (!$actor instanceof User) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('web_runtime_identity', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }

        $nonce = $request->request->get('nonce');
        if (!\is_string($nonce) || 1 !== preg_match('/^[A-Za-z0-9]{16,64}$/', $nonce)) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        return $this->noStore(new JsonResponse($this->reader->read($nonce)));
    }

    private function freshSuperAdmin(): ?User
    {
        $sessionUser = $this->getUser();
        if (!$sessionUser instanceof User) {
            return null;
        }

        try {
            return $this->entityManager->wrapInTransaction(function () use ($sessionUser): ?User {
                $actor = $this->freshUsers->findFreshLockedUser($sessionUser->getId(), LockMode::PESSIMISTIC_READ);
                if (!$actor instanceof User || !$this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($actor)) {
                    return null;
                }

                return $actor;
            });
        } catch (\Throwable) {
            return null;
        }
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}

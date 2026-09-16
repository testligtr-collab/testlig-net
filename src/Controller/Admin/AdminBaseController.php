<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\CommerceFailureReason;
use App\Exception\CommerceException;
use App\Service\Admin\AdminNavBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared admin panel helpers: actor id, shell chrome, no-store headers, exception mapping.
 */
abstract class AdminBaseController extends AbstractController
{
    public function __construct(
        protected readonly AdminNavBuilder $adminNavBuilder,
    ) {
    }

    protected function requireActorId(): \Symfony\Component\Uid\Uuid
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user->getId();
    }

    protected function requireActorUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function renderAdmin(string $view, array $parameters = [], ?Response $response = null): Response
    {
        $actor = $this->requireActorUser();
        $chrome = $this->adminNavBuilder->build($actor);
        $response = $this->render($view, array_merge($chrome, [
            'notifications' => [],
        ], $parameters), $response);
        $this->applyNoStore($response);

        return $response;
    }

    protected function applyNoStore(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
    }

    protected function mapCommerceException(CommerceException $e): never
    {
        match ($e->getReason()) {
            CommerceFailureReason::Unauthorized => throw new AccessDeniedHttpException('Bu işlem için yetkiniz yok.', $e),
            CommerceFailureReason::NotFound => throw new NotFoundHttpException('Kayıt bulunamadı.', $e),
            default => throw $e,
        };
    }
}

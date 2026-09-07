<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\HealthChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    public function __construct(
        private readonly HealthChecker $healthChecker,
    ) {
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $payload = $this->healthChecker->check();

        // Always 200 when the probe itself runs; overall readiness is in `status`.
        // `unavailable` (database down) uses 503 so orchestrators can react.
        $statusCode = 'unavailable' === $payload['status']
            ? Response::HTTP_SERVICE_UNAVAILABLE
            : Response::HTTP_OK;

        return $this->json($payload, $statusCode);
    }
}

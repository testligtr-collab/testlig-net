<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PaymentWebhookIngress;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exact POST webhook ingress. Domain mutation lives in PaymentWebhookIngress only.
 */
final class PaymentWebhookController extends AbstractController
{
    public function __construct(
        private readonly PaymentWebhookIngress $ingress,
    ) {
    }

    #[Route(
        '/webhook/odeme/{providerCode}',
        name: 'app_payment_webhook',
        requirements: ['providerCode' => '[a-z][a-z0-9_]{1,31}'],
        methods: ['POST'],
    )]
    public function __invoke(string $providerCode, Request $request): JsonResponse
    {
        $result = $this->ingress->handle($providerCode, $request);

        return $this->json(
            ['status' => $result->accepted ? 'ok' : 'error', 'reason' => $result->reasonCode],
            $result->httpStatus,
        );
    }

    #[Route(
        '/webhook/odeme/{providerCode}',
        name: 'app_payment_webhook_method_not_allowed',
        requirements: ['providerCode' => '[a-z][a-z0-9_]{1,31}'],
        methods: ['GET', 'PUT', 'PATCH', 'DELETE', 'HEAD'],
    )]
    public function methodNotAllowed(): Response
    {
        return new JsonResponse(['status' => 'error', 'reason' => 'method_not_allowed'], Response::HTTP_METHOD_NOT_ALLOWED);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Exception\CommerceException;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use App\Service\Admin\AdminPaymentOperationsQuery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminPaymentController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminPaymentOperationsQuery $paymentQuery,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/odemeler', name: 'app_admin_payments', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_PAYMENT_OPS)]
    public function list(Request $request): Response
    {
        $filters = [
            'status' => $request->query->getString('status') ?: null,
            'provider' => $request->query->getString('provider') ?: null,
            'environment' => $request->query->getString('environment') ?: null,
            'created_from' => $request->query->getString('created_from') ?: null,
            'created_to' => $request->query->getString('created_to') ?: null,
            'order_public_reference' => $request->query->getString('order_public_reference') ?: null,
            'needs_reconciliation' => null,
            'page' => $request->query->getInt('page', 1),
            'page_size' => $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE),
        ];

        $needsRaw = $request->query->get('needs_reconciliation');
        if (null !== $needsRaw && '' !== $needsRaw) {
            $filters['needs_reconciliation'] = filter_var($needsRaw, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
        }

        try {
            $result = $this->paymentQuery->listAttempts($this->requireActorId(), $filters);
        } catch (CommerceException $e) {
            if (\App\Enum\CommerceFailureReason::InvalidInput === $e->getReason()) {
                throw $this->createNotFoundException('Geçersiz filtre.', $e);
            }
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/payments/list.html.twig', [
            'result' => $result,
            'filters' => $filters,
        ]);
    }

    #[Route('/yonetim/odemeler/{attemptId}', name: 'app_admin_payment_detail', methods: ['GET'], requirements: ['attemptId' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_PAYMENT_OPS)]
    public function detail(Uuid $attemptId): Response
    {
        try {
            $view = $this->paymentQuery->getAttemptDetail($this->requireActorId(), $attemptId);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/payments/detail.html.twig', [
            'attempt' => $view,
        ]);
    }
}

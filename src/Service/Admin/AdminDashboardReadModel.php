<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminDashboardView;
use App\Dto\PaymentOperationsSummaryView;
use App\Entity\CommerceFulfillment;
use App\Entity\CommerceOrder;
use App\Entity\PaymentAttempt;
use App\Enum\CommerceFulfillmentStatus;
use App\Enum\CommerceOrderStatus;
use App\Enum\PaymentAttemptStatus;
use App\Security\AdminAuthorization;
use App\Service\PaymentOperationsReadModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Admin dashboard aggregates. Payment metrics require SUPER_ADMIN.
 */
final class AdminDashboardReadModel
{
    public function __construct(
        private readonly AdminActorGuard $actorGuard,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly PaymentOperationsReadModel $paymentOps,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getDashboard(Uuid $actorId): AdminDashboardView
    {
        $actor = $this->actorGuard->requireShell($actorId);
        $canPayments = $this->adminAuthorization->canOperatePayments($actor);

        if (!$canPayments) {
            return new AdminDashboardView(
                canViewPaymentMetrics: false,
                operationsSummary: null,
                awaitingPaymentOrderCount: null,
                capturedAwaitingFulfillmentCount: null,
                highlights: [
                    ['label' => 'Kapsam', 'value' => 'Sistem özeti'],
                    ['label' => 'Ödeme operasyonları', 'value' => 'Bu rol için gizli'],
                ],
            );
        }

        $summary = $this->paymentOps->getOperationsSummary($actorId);
        $awaiting = $this->countAwaitingPaymentOrders();
        $capturedPending = $this->countCapturedAwaitingFulfillment();

        return new AdminDashboardView(
            canViewPaymentMetrics: true,
            operationsSummary: $summary,
            awaitingPaymentOrderCount: $awaiting,
            capturedAwaitingFulfillmentCount: $capturedPending,
            highlights: $this->buildHighlights($summary, $awaiting, $capturedPending),
        );
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function buildHighlights(
        PaymentOperationsSummaryView $summary,
        int $awaiting,
        int $capturedPending,
    ): array {
        $initiated = $summary->attemptCountsByStatus[PaymentAttemptStatus::Initiated->value] ?? 0;
        $captured = $summary->attemptCountsByStatus[PaymentAttemptStatus::Captured->value] ?? 0;

        return [
            ['label' => 'Başlatılan denemeler', 'value' => (string) $initiated],
            ['label' => 'Tahsil edilen', 'value' => (string) $captured],
            ['label' => 'Ödeme bekleyen sipariş', 'value' => (string) $awaiting],
            ['label' => 'Tahsil / yerine getirme bekleyen', 'value' => (string) $capturedPending],
            ['label' => 'Due webhook', 'value' => (string) $summary->dueWebhookCount],
            ['label' => 'Dead letter', 'value' => (string) $summary->deadLetterCount],
        ];
    }

    private function countAwaitingPaymentOrders(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(CommerceOrder::class, 'o')
            ->andWhere('o.status = :status')
            ->setParameter('status', CommerceOrderStatus::AwaitingPayment)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countCapturedAwaitingFulfillment(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(PaymentAttempt::class, 'a')
            ->leftJoin(
                CommerceFulfillment::class,
                'f',
                'WITH',
                'IDENTITY(f.paymentAttempt) = a.id AND f.status = :completed',
            )
            ->andWhere('a.status = :captured')
            ->andWhere('f.id IS NULL')
            ->setParameter('captured', PaymentAttemptStatus::Captured)
            ->setParameter('completed', CommerceFulfillmentStatus::Completed)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

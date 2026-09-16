<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Admin dashboard projection. Payment metrics are null when the actor is not SA.
 */
final class AdminDashboardView
{
    /**
     * @param list<array{label: string, value: string}> $highlights
     */
    public function __construct(
        public readonly bool $canViewPaymentMetrics,
        public readonly ?PaymentOperationsSummaryView $operationsSummary,
        public readonly ?int $awaitingPaymentOrderCount,
        public readonly ?int $capturedAwaitingFulfillmentCount,
        public readonly array $highlights,
    ) {
    }
}

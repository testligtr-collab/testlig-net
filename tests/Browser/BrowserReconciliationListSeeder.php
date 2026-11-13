<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Entity\PaymentReconciliationRun;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentReconciliationMode;
use App\Enum\PaymentReconciliationRunStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synthetic reconciliation runs for browser acceptance of GET /yonetim/uzlastirma.
 *
 * Uses domain constructors only — no reconcile CLI, provider adapter, or webhook enqueue.
 */
final class BrowserReconciliationListSeeder
{
    public const LONG_PROVIDER = 'sandbox_provider_long_code_xx';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{
     *     list: string,
     *     longProvider: string,
     *     pagination: string
     * }
     */
    public function seed(User $superAdmin, Subject $subject): array
    {
        unset($superAdmin, $subject);

        $base = new \DateTimeImmutable('2026-09-13 15:00:00', new \DateTimeZone('UTC'));

        $this->persist('completed', 'sandbox_provider', PaymentProviderEnvironment::Sandbox, $base, PaymentReconciliationRunStatus::Completed, 2, 2, 0, 0);
        $this->persist('failed', 'sandbox_provider', PaymentProviderEnvironment::Sandbox, $base->modify('+1 minute'), PaymentReconciliationRunStatus::Failed, 1, 0, 0, 1);
        $this->persist('discrepancy', 'sandbox_provider', PaymentProviderEnvironment::Sandbox, $base->modify('+2 minutes'), PaymentReconciliationRunStatus::CompletedWithDiscrepancies, 3, 1, 2, 0);
        $this->persist('running', 'sandbox_provider', PaymentProviderEnvironment::Sandbox, $base->modify('+3 minutes'), PaymentReconciliationRunStatus::Running, 0, 0, 0, 0);
        $this->persist('long', self::LONG_PROVIDER, PaymentProviderEnvironment::Sandbox, $base->modify('+4 minutes'), PaymentReconciliationRunStatus::Completed, 1, 1, 0, 0);
        $this->persist('live', 'sandbox_provider', PaymentProviderEnvironment::Production, $base->modify('+5 minutes'), PaymentReconciliationRunStatus::Completed, 1, 1, 0, 0);

        $this->entityManager->flush();

        return [
            'list' => '/yonetim/uzlastirma',
            'longProvider' => self::LONG_PROVIDER,
            'pagination' => '/yonetim/uzlastirma?page_size=2&page=1',
        ];
    }

    private function persist(
        string $suffix,
        string $providerCode,
        PaymentProviderEnvironment $environment,
        \DateTimeImmutable $startedAt,
        PaymentReconciliationRunStatus $status,
        int $checked,
        int $matched,
        int $discrepancy,
        int $failed,
    ): void {
        unset($suffix);

        $run = PaymentReconciliationRun::start(
            providerCode: $providerCode,
            environment: $environment,
            mode: PaymentReconciliationMode::Manual,
            reasonCode: 'manual_reconciliation',
            startedAt: $startedAt,
            createdBy: null,
        );
        if (PaymentReconciliationRunStatus::Running !== $status) {
            $run->complete(
                $status,
                $checked,
                $matched,
                $discrepancy,
                $failed,
                $startedAt->modify('+30 seconds'),
            );
        }
        $this->entityManager->persist($run);
    }
}

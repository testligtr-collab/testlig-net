<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminSystemHealthView;
use App\Security\AdminAuthorization;
use App\Service\HealthChecker;
use App\Service\PaymentOperationsReadModel;
use Doctrine\Migrations\DependencyFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * System health probes for the admin panel. Never exposes DSN or exception text.
 */
final class AdminSystemHealthReadModel
{
    private const UNAVAILABLE = 'Kullanılamıyor';
    private const OK = 'Tamam';
    private const CURRENT = 'Güncel';
    private const NOT_CURRENT = 'Güncel değil';

    public function __construct(
        private readonly AdminActorGuard $actorGuard,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly HealthChecker $healthChecker,
        private readonly PaymentOperationsReadModel $paymentOps,
        private readonly DependencyFactory $migrations,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getSystemHealth(Uuid $actorId): AdminSystemHealthView
    {
        $actor = $this->actorGuard->requireSystemView($actorId);
        $probe = $this->healthChecker->check();

        $app = match ($probe['status']) {
            'ok' => self::OK,
            'degraded' => 'Kısmi',
            default => self::UNAVAILABLE,
        };
        $database = 'ok' === $probe['checks']['database'] ? self::OK : self::UNAVAILABLE;
        $redis = 'ok' === $probe['checks']['redis'] ? self::OK : self::UNAVAILABLE;
        $migration = $this->resolveMigrationStatus();

        $webhookSummary = null;
        if ($this->adminAuthorization->canOperatePayments($actor)) {
            try {
                $queue = $this->paymentOps->getWebhookQueueSummary($actorId);
                $webhookSummary = [
                    'due' => $queue->dueCount,
                    'retry_pending' => $queue->retryPendingCount,
                    'processing' => $queue->processingCount,
                    'stale_lease' => $queue->staleLeaseCount,
                    'dead_letter' => $queue->deadLetterCount,
                ];
            } catch (\Throwable $e) {
                $this->logger->warning('Admin system health: webhook summary unavailable.', [
                    'exception_class' => $e::class,
                ]);
                $webhookSummary = null;
            }
        }

        return new AdminSystemHealthView(
            applicationStatus: $app,
            databaseStatus: $database,
            redisStatus: $redis,
            migrationStatus: $migration,
            utcNote: 'Tüm kalıcı zaman damgaları UTC saklanır; gösterim kullanıcı saat dilimine çevrilir.',
            webhookQueueSummary: $webhookSummary,
        );
    }

    private function resolveMigrationStatus(): string
    {
        try {
            $statusCalculator = $this->migrations->getMigrationStatusCalculator();
            $newCount = $statusCalculator->getNewMigrations()->count();
            $executedUnavailable = $statusCalculator->getExecutedUnavailableMigrations()->count();

            if (0 === $newCount && 0 === $executedUnavailable) {
                return self::CURRENT;
            }

            return self::NOT_CURRENT;
        } catch (\Throwable $e) {
            $this->logger->warning('Admin system health: migration probe failed.', [
                'exception_class' => $e::class,
            ]);

            return self::UNAVAILABLE;
        }
    }
}

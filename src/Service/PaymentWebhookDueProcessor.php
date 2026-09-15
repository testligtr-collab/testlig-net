<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PaymentWebhookBatchProcessSummary;
use App\Dto\SecurityAuditContext;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\PaymentWebhookInboxStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CommerceException;
use App\Repository\PaymentWebhookInboxEventRepository;
use App\Time\UtcInstant;
use Psr\Clock\ClockInterface;

/**
 * Batch due-webhook processing for ops workers. Domain mutations stay in PaymentWebhookProcessor.
 */
final class PaymentWebhookDueProcessor
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 500;

    public function __construct(
        private readonly PaymentWebhookInboxEventRepository $inbox,
        private readonly PaymentWebhookProcessor $processor,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(
        int $limit,
        ?string $providerCode = null,
        ?PaymentProviderEnvironment $environment = null,
        bool $dryRun = false,
    ): PaymentWebhookBatchProcessSummary {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw CommerceException::invalidInput('limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        $now = UtcInstant::ensure($this->clock->now());
        $ids = $this->inbox->findDueEventIds($limit, $providerCode, $environment, $now);
        $selected = \count($ids);

        if ($dryRun) {
            return new PaymentWebhookBatchProcessSummary(
                selected: $selected,
                processed: 0,
                rejected: 0,
                retryPending: 0,
                deadLetter: 0,
                failed: 0,
                skipped: $selected,
                dryRun: true,
            );
        }

        $processed = 0;
        $rejected = 0;
        $retryPending = 0;
        $deadLetter = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            try {
                $event = $this->processor->process($id);
                match ($event->getProcessingStatus()) {
                    PaymentWebhookInboxStatus::Processed => ++$processed,
                    PaymentWebhookInboxStatus::Rejected => ++$rejected,
                    PaymentWebhookInboxStatus::RetryPending => ++$retryPending,
                    PaymentWebhookInboxStatus::DeadLetter => ++$deadLetter,
                    default => ++$skipped,
                };
            } catch (\Throwable) {
                ++$failed;
            }
        }

        $summary = new PaymentWebhookBatchProcessSummary(
            selected: $selected,
            processed: $processed,
            rejected: $rejected,
            retryPending: $retryPending,
            deadLetter: $deadLetter,
            failed: $failed,
            skipped: $skipped,
        );

        $this->auditRecorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::PaymentWebhookBatchProcessed,
            actorType: SecurityAuditActorType::Cli,
            outcome: $failed > 0 ? SecurityAuditOutcome::Failure : SecurityAuditOutcome::Success,
            metadata: [
                'source' => 'payment_webhook_due_batch',
                'reason_code' => 'batch_processed',
                'provider_code' => $providerCode,
                'environment' => $environment?->value,
                'selected_count' => $selected,
                'processed_count' => $processed,
                'rejected_count' => $rejected,
                'retry_pending_count' => $retryPending,
                'dead_letter_count' => $deadLetter,
                'failed_count' => $failed,
                'skipped_count' => $skipped,
            ],
            captureRequestHashes: false,
        ));

        return $summary;
    }
}

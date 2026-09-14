<?php

declare(strict_types=1);

namespace App\Service;

use App\Commerce\PaymentProviderRegistry;
use App\Commerce\PaymentWebhookVerificationRequest;
use App\Commerce\VerifiedPaymentWebhook;
use App\Dto\PaymentWebhookIngressResult;
use App\Dto\SecurityAuditContext;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentWebhookInboxEvent;
use App\Enum\PaymentWebhookInboxStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CommerceException;
use App\Repository\PaymentAttemptRepository;
use App\Repository\PaymentWebhookInboxEventRepository;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Accepts provider webhooks: size/content-type/signature/timestamp, then inbox + process.
 *
 * Denial audit is intentionally sparse (no body/signature/PII) to avoid log amplification.
 */
final class PaymentWebhookIngress
{
    public const MAX_BODY_BYTES = 65536;

    public const EXPECTED_CONTENT_TYPE = 'application/json';

    public function __construct(
        private readonly PaymentProviderRegistry $registry,
        private readonly PaymentWebhookInboxEventRepository $inbox,
        private readonly PaymentAttemptRepository $attempts,
        private readonly PaymentWebhookProcessor $processor,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(string $providerCode, Request $request): PaymentWebhookIngressResult
    {
        $providerCode = strtolower(trim($providerCode));
        try {
            if (!$this->registry->has($providerCode)) {
                $this->recordDenial('unknown_provider', $providerCode);

                return PaymentWebhookIngressResult::rejected(404, 'unknown_provider');
            }

            $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
            if (!str_starts_with($contentType, self::EXPECTED_CONTENT_TYPE)) {
                $this->recordDenial('unsupported_media_type', $providerCode);

                return PaymentWebhookIngressResult::rejected(415, 'unsupported_media_type');
            }

            $rawBody = $request->getContent();
            if (\strlen($rawBody) > self::MAX_BODY_BYTES) {
                $this->recordDenial('payload_too_large', $providerCode);

                return PaymentWebhookIngressResult::rejected(413, 'payload_too_large');
            }
            if ('' === $rawBody) {
                $this->recordDenial('empty_body', $providerCode);

                return PaymentWebhookIngressResult::rejected(400, 'invalid_request');
            }

            $registration = $this->registry->get($providerCode);
            $verifier = $registration->verifier;
            $signatureHeader = (string) $request->headers->get($this->signatureHeaderName($providerCode), '');
            $timestampHeader = (string) $request->headers->get($this->timestampHeaderName($providerCode), '');

            $verification = new PaymentWebhookVerificationRequest(
                providerCode: $providerCode,
                rawBody: $rawBody,
                signatureHeader: $signatureHeader,
                timestampHeader: $timestampHeader,
                receivedAt: UtcInstant::ensure($this->clock->now()),
            );

            $verifier->verify($verification);
            $verified = $registration->parser->parse($verification);

            return $this->acceptVerified($verified);
        } catch (CommerceException $e) {
            return $this->mapCommerceFailure($e, $providerCode);
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            return PaymentWebhookIngressResult::rejected(409, 'conflict');
        } catch (\Throwable) {
            $this->recordDenial('internal_error', $providerCode);

            return PaymentWebhookIngressResult::rejected(500, 'internal_error');
        }
    }

    private function acceptVerified(VerifiedPaymentWebhook $verified): PaymentWebhookIngressResult
    {
        $existing = $this->inbox->findOneByProviderEvent(
            $verified->providerCode,
            $verified->environment,
            $verified->providerEventReference,
        );
        if ($existing instanceof PaymentWebhookInboxEvent) {
            if (!hash_equals($existing->getPayloadHash(), $verified->payloadHash)) {
                $this->auditWebhook(
                    SecurityAuditAction::PaymentWebhookIntegrityFailed,
                    $existing,
                    'payload_hash_conflict',
                    SecurityAuditOutcome::Failure,
                );
                throw CommerceException::webhookIntegrityConflict();
            }
            if (PaymentWebhookInboxStatus::Processed === $existing->getProcessingStatus()) {
                $this->auditWebhook(
                    SecurityAuditAction::PaymentWebhookReceived,
                    $existing,
                    'duplicate_replay',
                    SecurityAuditOutcome::Success,
                );

                return PaymentWebhookIngressResult::accepted($existing);
            }
            if ($existing->getProcessingStatus()->isTerminal()) {
                return PaymentWebhookIngressResult::accepted($existing);
            }

            $processed = $this->processor->process($existing->getId());

            return PaymentWebhookIngressResult::accepted($processed);
        }

        $attempt = null;
        if ($verified->paymentAttemptId instanceof \Symfony\Component\Uid\Uuid) {
            $attempt = $this->attempts->findOneById($verified->paymentAttemptId);
        }

        $inboxEvent = PaymentWebhookInboxEvent::receiveVerified($verified, $attempt);
        try {
            $this->entityManager->wrapInTransaction(function () use ($inboxEvent, $verified): void {
                $this->inbox->save($inboxEvent, false);
                $this->auditWebhook(
                    SecurityAuditAction::PaymentWebhookReceived,
                    $inboxEvent,
                    'received',
                    SecurityAuditOutcome::Success,
                    flush: false,
                );
                $this->entityManager->flush();
                unset($verified);
            });
        } catch (UniqueConstraintViolationException) {
            $race = $this->inbox->findOneByProviderEvent(
                $verified->providerCode,
                $verified->environment,
                $verified->providerEventReference,
            );
            if (!$race instanceof PaymentWebhookInboxEvent) {
                throw CommerceException::conflict();
            }
            if (!hash_equals($race->getPayloadHash(), $verified->payloadHash)) {
                throw CommerceException::webhookIntegrityConflict();
            }

            return PaymentWebhookIngressResult::accepted(
                $race->getProcessingStatus()->isTerminal()
                    ? $race
                    : $this->processor->process($race->getId()),
            );
        }

        $processed = $this->processor->process($inboxEvent->getId());

        return PaymentWebhookIngressResult::accepted($processed, $attempt instanceof PaymentAttempt ? $attempt : null);
    }

    private function mapCommerceFailure(CommerceException $e, string $providerCode): PaymentWebhookIngressResult
    {
        $reason = $e->getReason()->value;
        $this->recordDenial($reason, $providerCode);

        return match ($e->getReason()) {
            \App\Enum\CommerceFailureReason::WebhookSignatureInvalid => PaymentWebhookIngressResult::rejected(401, 'signature_invalid'),
            \App\Enum\CommerceFailureReason::WebhookReplayRejected => PaymentWebhookIngressResult::rejected(401, 'replay_rejected'),
            \App\Enum\CommerceFailureReason::WebhookIntegrityConflict => PaymentWebhookIngressResult::rejected(409, 'integrity_conflict'),
            \App\Enum\CommerceFailureReason::ProviderMismatch, \App\Enum\CommerceFailureReason::ProviderUnavailable => PaymentWebhookIngressResult::rejected(404, 'unknown_provider'),
            \App\Enum\CommerceFailureReason::Conflict, \App\Enum\CommerceFailureReason::IdempotencyConflict => PaymentWebhookIngressResult::rejected(409, 'conflict'),
            default => PaymentWebhookIngressResult::rejected(400, 'invalid_request'),
        };
    }

    private function recordDenial(string $reasonCode, string $providerCode): void
    {
        // High-volume webhook denials: structured log without body/signature/PII amplification.
        $this->logger->notice('payment_webhook_denied', [
            'reason_code' => $reasonCode,
            'provider_code' => $providerCode,
        ]);
        try {
            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::PaymentWebhookRejected,
                actorType: SecurityAuditActorType::System,
                outcome: SecurityAuditOutcome::Failure,
                metadata: [
                    'source' => 'payment_webhook_ingress',
                    'reason_code' => $reasonCode,
                    'provider_code' => $providerCode,
                ],
                captureRequestHashes: false,
            ), true);
        } catch (\Throwable) {
            // Never fail closed on denial audit; response already generic.
        }
    }

    private function auditWebhook(
        SecurityAuditAction $action,
        PaymentWebhookInboxEvent $event,
        string $reasonCode,
        SecurityAuditOutcome $outcome,
        bool $flush = true,
    ): void {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: SecurityAuditActorType::System,
            outcome: $outcome,
            metadata: [
                'source' => 'payment_webhook_ingress',
                'reason_code' => $reasonCode,
                'provider_code' => $event->getProviderCode(),
                'provider_environment' => $event->getEnvironment()->value,
                'event_type' => $event->getEventType()->value,
                'inbox_event_id' => $event->getId()->toRfc4122(),
                'payment_attempt_id' => $event->getPaymentAttempt()?->getId()->toRfc4122(),
                'processing_status' => $event->getProcessingStatus()->value,
            ],
            captureRequestHashes: false,
        ), $flush);
    }

    private function signatureHeaderName(string $providerCode): string
    {
        return match ($providerCode) {
            \App\Commerce\Sandbox\SandboxPaymentProviderAdapter::PROVIDER_CODE => \App\Commerce\Sandbox\SandboxWebhookSignatureVerifier::HEADER_SIGNATURE,
            default => 'X-Webhook-Signature',
        };
    }

    private function timestampHeaderName(string $providerCode): string
    {
        return match ($providerCode) {
            \App\Commerce\Sandbox\SandboxPaymentProviderAdapter::PROVIDER_CODE => \App\Commerce\Sandbox\SandboxWebhookSignatureVerifier::HEADER_TIMESTAMP,
            default => 'X-Webhook-Timestamp',
        };
    }
}

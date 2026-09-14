<?php

declare(strict_types=1);

namespace App\Service;

use App\Commerce\PaymentProviderChargeRequest;
use App\Commerce\PaymentProviderChargeResult;
use App\Commerce\PaymentProviderRegistry;
use App\Commerce\Sandbox\SandboxProviderAmbiguousException;
use App\Dto\PaymentCheckoutResult;
use App\Dto\SecurityAuditContext;
use App\Entity\CommerceOrder;
use App\Entity\PaymentAttempt;
use App\Entity\User;
use App\Enum\PaymentAttemptStatus;
use App\Enum\PaymentCheckoutOutcome;
use App\Enum\PaymentEventType;
use App\Enum\PaymentProviderEnvironment;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CommerceException;
use App\Security\CommerceAuthorization;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;

/**
 * Orchestrates provider-neutral checkout: attempt → adapter (outside DB TX) → settlement.
 *
 * Network timeout / ambiguous provider results stay fail-closed (attempt not marked failed).
 * Client pricing is never accepted; amount/currency come from the sealed order only.
 *
 * Lock order (settlement path): Institution → purchaser/actor → CommerceOrder →
 * PaymentAttempt → PaymentEvent → Audit. Provider HTTP must not run inside an open DB TX.
 */
final class PaymentCheckoutOrchestrator
{
    public function __construct(
        private readonly PaymentProviderRegistry $registry,
        private readonly PaymentAttemptManager $attemptManager,
        private readonly PaymentSettlementManager $settlementManager,
        private readonly CommerceOrderManager $orderManager,
        private readonly CommerceAuthorization $authorization,
        private readonly PaymentPlatformSettlementActorResolver $settlementActors,
        private readonly CommerceFreshEntityLoader $freshCommerce,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly SecurityAuditRecorder $auditRecorder,
    ) {
    }

    /**
     * Starts (or resumes) a payment attempt and drives authorize via the registered adapter.
     *
     * @param string $idempotencyKey Raw key — never persisted; only its HMAC digest is stored
     */
    public function checkout(
        CommerceOrder $order,
        User $actor,
        string $providerCode,
        PaymentProviderEnvironment $environment,
        string $idempotencyKey,
        string $reasonCode = 'checkout_authorize',
        bool $autoCapture = false,
    ): PaymentCheckoutResult {
        try {
            $registration = $this->registry->get($providerCode);
            $this->registry->assertEnvironment($providerCode, $environment);

            $orderId = $order->getId();
            $actorId = $actor->getId();
            $this->assertFreshPurchaseAuthorization($orderId, $actorId);

            $attempt = $this->attemptManager->start(
                $order,
                $actor,
                $providerCode,
                $environment,
                $idempotencyKey,
                $reasonCode,
            );

            $this->recordCheckoutAudit(
                SecurityAuditAction::PaymentCheckoutStarted,
                $actor,
                $attempt,
                $reasonCode,
                SecurityAuditOutcome::Success,
            );

            if ($attempt->getStatus()->isTerminal()
                || PaymentAttemptStatus::Authorized === $attempt->getStatus()
            ) {
                return $this->resumeExistingOutcome($attempt);
            }

            $chargeRequest = new PaymentProviderChargeRequest(
                orderId: $attempt->getOrder()->getId(),
                paymentAttemptId: $attempt->getId(),
                orderPublicReference: $attempt->getOrder()->getPublicReference(),
                amount: $attempt->getAmount(),
                // Opaque token for the provider only — never written to our tables/logs.
                idempotencyKey: hash('sha256', 'checkout:'.$idempotencyKey),
                providerPaymentReference: $attempt->getProviderPaymentReference(),
            );

            // Provider network call intentionally outside any open DB transaction.
            try {
                $providerResult = $registration->adapter->authorize($chargeRequest);
            } catch (SandboxProviderAmbiguousException) {
                $this->recordCheckoutAudit(
                    SecurityAuditAction::PaymentCheckoutProviderRejected,
                    $actor,
                    $attempt,
                    'provider_ambiguous',
                    SecurityAuditOutcome::Failure,
                );

                return new PaymentCheckoutResult(
                    PaymentCheckoutOutcome::Ambiguous,
                    $attempt,
                    null,
                    'provider_ambiguous',
                );
            } catch (\Throwable) {
                $this->recordCheckoutAudit(
                    SecurityAuditAction::PaymentCheckoutProviderRejected,
                    $actor,
                    $attempt,
                    'provider_unavailable',
                    SecurityAuditOutcome::Failure,
                );

                return new PaymentCheckoutResult(
                    PaymentCheckoutOutcome::Ambiguous,
                    $attempt,
                    null,
                    'provider_unavailable',
                );
            }

            $settlementActor = $this->settlementActors->resolve();
            $settled = $this->applyAuthorizeResult($attempt, $settlementActor, $providerResult, $reasonCode);

            if (PaymentEventType::Failed === $providerResult->eventType
                || PaymentEventType::Cancelled === $providerResult->eventType
            ) {
                $this->recordCheckoutAudit(
                    SecurityAuditAction::PaymentCheckoutProviderRejected,
                    $actor,
                    $settled,
                    $providerResult->failureCode ?? 'provider_declined',
                    SecurityAuditOutcome::Failure,
                );

                return new PaymentCheckoutResult(
                    PaymentCheckoutOutcome::Rejected,
                    $settled,
                    $providerResult->eventType,
                    $providerResult->failureCode ?? 'provider_declined',
                );
            }

            if ($autoCapture && PaymentEventType::Authorized === $providerResult->eventType) {
                $settled = $this->captureAfterAuthorize(
                    $settled,
                    $registration->adapter,
                    $settlementActor,
                    $idempotencyKey,
                    $reasonCode,
                );
            }

            $this->recordCheckoutAudit(
                SecurityAuditAction::PaymentCheckoutProviderAccepted,
                $actor,
                $settled,
                $reasonCode,
                SecurityAuditOutcome::Success,
            );

            return new PaymentCheckoutResult(
                PaymentCheckoutOutcome::Accepted,
                $settled,
                PaymentAttemptStatus::Captured === $settled->getStatus()
                    ? PaymentEventType::Captured
                    : PaymentEventType::Authorized,
            );
        } catch (CommerceException $e) {
            if (\in_array($e->getReason(), [
                \App\Enum\CommerceFailureReason::Conflict,
                \App\Enum\CommerceFailureReason::IdempotencyConflict,
            ], true)) {
                throw $e;
            }
            throw $e;
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }

    private function assertFreshPurchaseAuthorization(\Symfony\Component\Uid\Uuid $orderId, \Symfony\Component\Uid\Uuid $actorId): void
    {
        $lockedOrder = $this->freshCommerce->findFreshOrder($orderId, LockMode::NONE);
        if (!$lockedOrder instanceof CommerceOrder) {
            throw CommerceException::notFound();
        }
        $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::NONE);
        $freshActor = $users[$actorId->toRfc4122()] ?? null;
        if (!$freshActor instanceof User) {
            throw CommerceException::userNotFound();
        }
        $this->authorization->assertCanManageOrder($freshActor, $lockedOrder);
        $items = $this->freshCommerce->findFreshOrderItems($lockedOrder->getId());
        $this->orderManager->assertOrderIntegrity($lockedOrder, $items);
    }

    private function applyAuthorizeResult(
        PaymentAttempt $attempt,
        User $settlementActor,
        PaymentProviderChargeResult $result,
        string $reasonCode,
    ): PaymentAttempt {
        $idempotencyKey = 'checkout:settle:'.$attempt->getId()->toRfc4122().':'.$result->eventType->value;
        match ($result->eventType) {
            PaymentEventType::Authorized => $this->settlementManager->recordAuthorized(
                $attempt,
                $settlementActor,
                $result->amount ?? $attempt->getAmount(),
                $result->occurredAt,
                $idempotencyKey,
                $reasonCode,
                $result->providerPaymentReference,
                $result->providerAuthorizationReference,
                $result->providerEventReference,
                $result->sanitizedMetadata,
            ),
            PaymentEventType::Failed => $this->settlementManager->recordFailed(
                $attempt,
                $settlementActor,
                $result->failureCode ?? 'provider_declined',
                $result->occurredAt,
                $idempotencyKey,
                $reasonCode,
                $result->providerEventReference,
                $result->sanitizedMetadata,
            ),
            PaymentEventType::Cancelled => $this->settlementManager->recordCancelled(
                $attempt,
                $settlementActor,
                $result->failureCode ?? 'provider_cancelled',
                $result->occurredAt,
                $idempotencyKey,
                $reasonCode,
                $result->providerEventReference,
                $result->sanitizedMetadata,
            ),
            default => throw CommerceException::invalidInput('Unexpected authorize event type.'),
        };

        $fresh = $this->freshCommerce->findFreshPaymentAttempt($attempt->getId(), LockMode::NONE);
        if (!$fresh instanceof PaymentAttempt) {
            throw CommerceException::notFound();
        }

        return $fresh;
    }

    private function captureAfterAuthorize(
        PaymentAttempt $attempt,
        \App\Commerce\PaymentProviderAdapterInterface $adapter,
        User $settlementActor,
        string $rawIdempotencyKey,
        string $reasonCode,
    ): PaymentAttempt {
        $chargeRequest = new PaymentProviderChargeRequest(
            orderId: $attempt->getOrder()->getId(),
            paymentAttemptId: $attempt->getId(),
            orderPublicReference: $attempt->getOrder()->getPublicReference(),
            amount: $attempt->getAmount(),
            idempotencyKey: hash('sha256', 'checkout:capture:'.$rawIdempotencyKey),
            providerPaymentReference: $attempt->getProviderPaymentReference(),
        );

        try {
            $captureResult = $adapter->capture($chargeRequest);
        } catch (\Throwable) {
            // Authorize succeeded; capture uncertainty stays fail-closed (not failed).
            return $attempt;
        }

        if (PaymentEventType::Captured !== $captureResult->eventType) {
            return $attempt;
        }

        $this->settlementManager->recordCaptured(
            $attempt,
            $settlementActor,
            $captureResult->amount ?? $attempt->getAmount(),
            $captureResult->occurredAt,
            'checkout:settle:'.$attempt->getId()->toRfc4122().':captured',
            $reasonCode,
            $captureResult->providerPaymentReference,
            $captureResult->providerEventReference,
            $captureResult->sanitizedMetadata,
        );

        $fresh = $this->freshCommerce->findFreshPaymentAttempt($attempt->getId(), LockMode::NONE);
        if (!$fresh instanceof PaymentAttempt) {
            throw CommerceException::notFound();
        }

        return $fresh;
    }

    private function resumeExistingOutcome(PaymentAttempt $attempt): PaymentCheckoutResult
    {
        return match ($attempt->getStatus()) {
            PaymentAttemptStatus::Authorized => new PaymentCheckoutResult(
                PaymentCheckoutOutcome::Accepted,
                $attempt,
                PaymentEventType::Authorized,
            ),
            PaymentAttemptStatus::Captured => new PaymentCheckoutResult(
                PaymentCheckoutOutcome::Accepted,
                $attempt,
                PaymentEventType::Captured,
            ),
            PaymentAttemptStatus::Failed, PaymentAttemptStatus::Cancelled => new PaymentCheckoutResult(
                PaymentCheckoutOutcome::Rejected,
                $attempt,
                PaymentAttemptStatus::Failed === $attempt->getStatus()
                    ? PaymentEventType::Failed
                    : PaymentEventType::Cancelled,
                $attempt->getFailureCode(),
            ),
            default => new PaymentCheckoutResult(PaymentCheckoutOutcome::Ambiguous, $attempt, null, 'attempt_pending'),
        };
    }

    private function recordCheckoutAudit(
        SecurityAuditAction $action,
        User $actor,
        PaymentAttempt $attempt,
        string $reasonCode,
        SecurityAuditOutcome $outcome,
    ): void {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: SecurityAuditActorType::User,
            outcome: $outcome,
            actorUser: $actor,
            metadata: [
                'source' => 'payment_checkout_orchestrator',
                'reason_code' => $reasonCode,
                'order_id' => $attempt->getOrder()->getId()->toRfc4122(),
                'payment_attempt_id' => $attempt->getId()->toRfc4122(),
                'provider_code' => $attempt->getProviderCode(),
                'provider_environment' => $attempt->getEnvironment()->value,
                'status' => $attempt->getStatus()->value,
                'currency' => $attempt->getCurrency(),
                'amount_minor' => $attempt->getAmountMinor(),
            ],
            captureRequestHashes: false,
        ), true);
    }
}

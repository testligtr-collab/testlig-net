<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PaymentWebhookDeadLetterRequeueResult;
use App\Dto\SecurityAuditContext;
use App\Entity\PaymentWebhookInboxEvent;
use App\Entity\User;
use App\Enum\PaymentWebhookInboxStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CommerceException;
use App\Repository\PaymentWebhookInboxEventRepository;
use App\Security\CommerceAuthorization;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Controlled SUPER_ADMIN dead-letter → retry_pending requeue with same-TX audit.
 */
final class PaymentWebhookDeadLetterRequeueService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentWebhookInboxEventRepository $inbox,
        private readonly FreshUserLoader $freshUsers,
        private readonly CommerceAuthorization $commerceAuthorization,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ClockInterface $clock,
    ) {
    }

    public function requeue(
        Uuid $eventId,
        Uuid $actorId,
        string $reasonCode,
        bool $confirm,
    ): PaymentWebhookDeadLetterRequeueResult {
        if (!$confirm) {
            throw CommerceException::invalidInput('confirm is required.');
        }

        try {
            return $this->em->wrapInTransaction(function () use ($eventId, $actorId, $reasonCode): PaymentWebhookDeadLetterRequeueResult {
                $actor = $this->freshUsers->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$actor instanceof User) {
                    throw CommerceException::unauthorized();
                }
                $this->commerceAuthorization->assertCanOperatePayments($actor);

                $event = $this->inbox->findFreshForUpdate($eventId);
                if (!$event instanceof PaymentWebhookInboxEvent) {
                    throw CommerceException::notFound();
                }
                if (PaymentWebhookInboxStatus::DeadLetter !== $event->getProcessingStatus()) {
                    throw CommerceException::invalidTransition();
                }

                $now = UtcInstant::ensure($this->clock->now());
                $event->requeueFromDeadLetter($reasonCode, $now);
                $this->em->flush();

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::PaymentWebhookDeadLetterRequeued,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $actor,
                    metadata: [
                        'source' => 'payment_webhook_dead_letter_requeue',
                        'reason_code' => $reasonCode,
                        'provider_code' => $event->getProviderCode(),
                        'environment' => $event->getEnvironment()->value,
                        'processing_status' => $event->getProcessingStatus()->value,
                        'attempt_count' => $event->getAttemptCount(),
                        'inbox_event_id' => $event->getId()->toRfc4122(),
                    ],
                    captureRequestHashes: false,
                ), flush: true);

                return new PaymentWebhookDeadLetterRequeueResult(
                    eventId: $event->getId(),
                    status: $event->getProcessingStatus(),
                    providerCode: $event->getProviderCode(),
                    environment: $event->getEnvironment(),
                    attemptCount: $event->getAttemptCount(),
                    reasonCode: $reasonCode,
                );
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CommerceException::conflict();
        }
    }
}

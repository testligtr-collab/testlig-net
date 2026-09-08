<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\SecurityAuditEvent;
use App\Repository\SecurityAuditEventRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Sole application entry point for creating security audit events.
 */
final class SecurityAuditRecorder
{
    /** @var array<string, true> */
    private array $recordedFingerprints = [];

    public function __construct(
        private readonly SecurityAuditEventRepository $events,
        private readonly SecurityAuditMetadataSanitizer $metadataSanitizer,
        private readonly SecurityAuditHashGenerator $hashGenerator,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Persist an audit event. Caller controls flush/transaction boundaries via $flush.
     */
    public function record(SecurityAuditContext $context, bool $flush = true): ?SecurityAuditEvent
    {
        $fingerprint = $this->fingerprint($context);
        if (isset($this->recordedFingerprints[$fingerprint])) {
            return null;
        }

        $metadata = $this->metadataSanitizer->sanitize($context->metadata);
        $correlationId = $context->correlationId;
        $ipHash = null;
        $userAgentHash = null;

        $request = $this->requestStack->getCurrentRequest();
        if ($context->captureRequestHashes && null !== $request) {
            $correlationId ??= $request->headers->get('X-Request-Id') ?: Uuid::v7()->toRfc4122();
            $ipHash = $this->hashGenerator->hashIp($request->getClientIp());
            $userAgentHash = $this->hashGenerator->hashUserAgent($request->headers->get('User-Agent'));
        } elseif (null === $correlationId) {
            $correlationId = Uuid::v7()->toRfc4122();
        }

        $occurredAt = \DateTimeImmutable::createFromInterface($this->clock->now());
        $event = SecurityAuditEvent::create(
            action: $context->action,
            actorType: $context->actorType,
            outcome: $context->outcome,
            occurredAt: $occurredAt,
            actorUser: $context->actorUser,
            subjectUser: $context->subjectUser,
            metadata: $metadata,
            correlationId: $correlationId,
            ipHash: $ipHash,
            userAgentHash: $userAgentHash,
        );

        $this->events->save($event, $flush);
        $this->recordedFingerprints[$fingerprint] = true;

        return $event;
    }

    public function resetRequestDedup(): void
    {
        $this->recordedFingerprints = [];
    }

    private function fingerprint(SecurityAuditContext $context): string
    {
        $subject = $context->subjectUser?->getId()->toRfc4122() ?? '-';
        $actor = $context->actorUser?->getId()->toRfc4122() ?? '-';

        return implode('|', [
            $context->action->value,
            $context->actorType->value,
            $context->outcome->value,
            $actor,
            $subject,
            $context->correlationId ?? '',
        ]);
    }
}

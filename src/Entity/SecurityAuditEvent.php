<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Repository\SecurityAuditEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Append-only security audit event. No public setters; updates/deletes are blocked.
 *
 * Future controlled retention/purge is out of scope for this stage (see docs/architecture.md).
 */
#[ORM\Entity(repositoryClass: SecurityAuditEventRepository::class)]
#[ORM\Table(name: 'security_audit_events')]
#[ORM\Index(name: 'idx_security_audit_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_security_audit_action', columns: ['action'])]
#[ORM\Index(name: 'idx_security_audit_actor_user', columns: ['actor_user_id'])]
#[ORM\Index(name: 'idx_security_audit_subject_user', columns: ['subject_user_id'])]
#[ORM\Index(name: 'idx_security_audit_action_occurred', columns: ['action', 'occurred_at'])]
class SecurityAuditEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 64, enumType: SecurityAuditAction::class)]
    private SecurityAuditAction $action;

    #[ORM\Column(name: 'actor_type', length: 16, enumType: SecurityAuditActorType::class)]
    private SecurityAuditActorType $actorType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'actor_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actorUser;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $subjectUser;

    #[ORM\Column(length: 16, enumType: SecurityAuditOutcome::class)]
    private SecurityAuditOutcome $outcome;

    #[ORM\Column(name: 'correlation_id', length: 64, nullable: true)]
    private ?string $correlationId;

    #[ORM\Column(name: 'ip_hash', length: 64, nullable: true)]
    #[Ignore]
    private ?string $ipHash;

    #[ORM\Column(name: 'user_agent_hash', length: 64, nullable: true)]
    #[Ignore]
    private ?string $userAgentHash;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata;

    /**
     * @param array<string, mixed> $metadata
     *
     * @internal prefer SecurityAuditRecorder
     */
    public static function create(
        SecurityAuditAction $action,
        SecurityAuditActorType $actorType,
        SecurityAuditOutcome $outcome,
        \DateTimeImmutable $occurredAt,
        ?User $actorUser = null,
        ?User $subjectUser = null,
        array $metadata = [],
        ?string $correlationId = null,
        ?string $ipHash = null,
        ?string $userAgentHash = null,
        ?Uuid $id = null,
    ): self {
        $event = new self();
        $event->id = $id ?? new UuidV7();
        $event->occurredAt = $occurredAt;
        $event->action = $action;
        $event->actorType = $actorType;
        $event->actorUser = $actorUser;
        $event->subjectUser = $subjectUser;
        $event->outcome = $outcome;
        $event->correlationId = $correlationId;
        $event->ipHash = $ipHash;
        $event->userAgentHash = $userAgentHash;
        $event->metadata = $metadata;

        return $event;
    }

    private function __construct()
    {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getAction(): SecurityAuditAction
    {
        return $this->action;
    }

    public function getActorType(): SecurityAuditActorType
    {
        return $this->actorType;
    }

    public function getActorUser(): ?User
    {
        return $this->actorUser;
    }

    public function getSubjectUser(): ?User
    {
        return $this->subjectUser;
    }

    public function getOutcome(): SecurityAuditOutcome
    {
        return $this->outcome;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function getIpHash(): ?string
    {
        return $this->ipHash;
    }

    public function getUserAgentHash(): ?string
    {
        return $this->userAgentHash;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

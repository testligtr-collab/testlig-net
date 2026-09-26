<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\ParentStudentLinkException;
use App\Repository\ParentStudentLinkCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One-time open parent-link code. Only the digest is stored.
 */
#[ORM\Entity(repositoryClass: ParentStudentLinkCodeRepository::class)]
#[ORM\Table(name: 'parent_student_link_codes')]
#[ORM\UniqueConstraint(name: 'uniq_psl_code_digest', columns: ['token_digest'])]
#[ORM\Index(name: 'idx_psl_code_student_created', columns: ['student_user_id', 'created_at'])]
class ParentStudentLinkCode
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $student;

    #[ORM\Column(name: 'token_digest', length: 64)]
    private string $tokenDigest;

    #[ORM\Column(name: 'pepper_key_id', length: 32)]
    private string $pepperKeyId;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'consumed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        User $student,
        string $tokenDigest,
        string $pepperKeyId,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $createdAt,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->student = $student;
        $this->tokenDigest = $tokenDigest;
        $this->pepperKeyId = $pepperKeyId;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
    }

    public static function issue(
        User $student,
        string $tokenDigest,
        string $pepperKeyId,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $tokenDigest)) {
            throw ParentStudentLinkException::invalidInput();
        }
        $pepperKeyId = trim($pepperKeyId);
        if ('' === $pepperKeyId || \strlen($pepperKeyId) > 32) {
            throw ParentStudentLinkException::invalidInput();
        }
        if ($expiresAt <= $now) {
            throw ParentStudentLinkException::invalidInput();
        }

        return new self($student, $tokenDigest, $pepperKeyId, $expiresAt, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStudent(): User
    {
        return $this->student;
    }

    public function getTokenDigest(): string
    {
        return $this->tokenDigest;
    }

    public function getPepperKeyId(): string
    {
        return $this->pepperKeyId;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return null === $this->consumedAt
            && null === $this->revokedAt
            && $this->expiresAt > $now;
    }

    public function consume(\DateTimeImmutable $now): void
    {
        if (!$this->isUsable($now)) {
            throw ParentStudentLinkException::codeRejected();
        }
        $this->consumedAt = $now;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        if (null !== $this->consumedAt || null !== $this->revokedAt) {
            throw ParentStudentLinkException::codeRejected();
        }
        $this->revokedAt = $now;
    }
}

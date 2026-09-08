<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SecurityBootstrapGuardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One-row-per-name guard used to make one-shot bootstrap concurrency-safe.
 */
#[ORM\Entity(repositoryClass: SecurityBootstrapGuardRepository::class)]
#[ORM\Table(name: 'security_bootstrap_guards')]
class SecurityBootstrapGuard
{
    public const SUPER_ADMIN = 'super_admin';

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    public static function forSuperAdmin(User $user, \DateTimeImmutable $createdAt): self
    {
        $guard = new self();
        $guard->name = self::SUPER_ADMIN;
        $guard->createdAt = $createdAt;
        $guard->user = $user;

        return $guard;
    }

    private function __construct()
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }
}

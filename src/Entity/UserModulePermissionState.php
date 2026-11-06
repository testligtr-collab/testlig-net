<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserModulePermissionStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Optimistic version for one account's direct module grants.
 */
#[ORM\Entity(repositoryClass: UserModulePermissionStateRepository::class)]
#[ORM\Table(name: 'user_module_permission_states')]
class UserModulePermissionState
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private int $version;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, \DateTimeImmutable $updatedAt)
    {
        $this->user = $user;
        $this->version = 0;
        $this->updatedAt = $updatedAt;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

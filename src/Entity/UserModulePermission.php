<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserModulePermissionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One direct module grant for one account. Role access is not stored here.
 */
#[ORM\Entity(repositoryClass: UserModulePermissionRepository::class)]
#[ORM\Table(name: 'user_module_permissions')]
#[ORM\UniqueConstraint(name: 'uniq_user_module_permission', columns: ['user_id', 'permission_code'])]
class UserModulePermission
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'permission_code', length: 64)]
    private string $permissionCode;

    public function __construct(User $user, string $permissionCode)
    {
        $this->id = new UuidV7();
        $this->user = $user;
        $this->permissionCode = $permissionCode;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPermissionCode(): string
    {
        return $this->permissionCode;
    }
}

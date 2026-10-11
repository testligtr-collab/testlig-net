<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LocationLevel;
use App\Repository\LocationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Global province, district, or school. Institution branches and class nodes are not stored here.
 */
#[ORM\Entity(repositoryClass: LocationRepository::class)]
#[ORM\Table(name: 'locations')]
#[ORM\Index(name: 'idx_locations_parent', columns: ['parent_id'])]
#[ORM\Index(name: 'idx_locations_parent_level_active', columns: ['parent_id', 'level', 'active'])]
class Location
{
    public const NAME_MAX = 150;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?self $parent;

    #[ORM\Column(length: 16, enumType: LocationLevel::class)]
    private LocationLevel $level;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column]
    private bool $active;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        LocationLevel $level,
        string $name,
        ?self $parent,
        bool $active,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $trimmed = trim($name);
        if ('' === $trimmed || mb_strlen($trimmed, 'UTF-8') > self::NAME_MAX) {
            throw new \InvalidArgumentException('Location name must be 1 to 150 characters.');
        }
        $this->id = $id ?? new UuidV7();
        $this->level = $level;
        $this->name = $trimmed;
        $this->parent = $parent;
        $this->active = $active;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function create(
        LocationLevel $level,
        string $name,
        ?self $parent,
        \DateTimeImmutable $now,
        bool $active = true,
        ?Uuid $id = null,
    ): self {
        return new self($level, $name, $parent, $active, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function getLevel(): LocationLevel
    {
        return $this->level;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @internal test and future catalog maintenance
     */
    public function deactivate(): void
    {
        $this->active = false;
        $this->updatedAt = new \DateTimeImmutable();
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NewsCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: NewsCategoryRepository::class)]
#[ORM\Table(name: 'news_categories')]
#[ORM\UniqueConstraint(name: 'uniq_news_category_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_news_category_created_by', columns: ['created_by_id'])]
class NewsCategory
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(string $name, string $slug, User $createdBy, \DateTimeImmutable $now)
    {
        $this->id = new UuidV7();
        $this->name = $name;
        $this->slug = $slug;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function create(string $name, string $slug, User $createdBy, \DateTimeImmutable $now): self
    {
        return new self($name, $slug, $createdBy, $now);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }
}

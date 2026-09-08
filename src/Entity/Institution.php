<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InstitutionStatus;
use App\Enum\InstitutionType;
use App\Exception\InstitutionOperationException;
use App\Repository\InstitutionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Multi-tenant education institution. Access requires active membership, not global roles.
 */
#[ORM\Entity(repositoryClass: InstitutionRepository::class)]
#[ORM\Table(name: 'institutions')]
#[ORM\UniqueConstraint(name: 'uniq_institutions_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_institutions_status', columns: ['status'])]
#[ORM\Index(name: 'idx_institutions_type_status', columns: ['type', 'status'])]
class Institution
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(name: 'normalized_name', length: 180)]
    private string $normalizedName;

    #[ORM\Column(length: 180)]
    private string $slug;

    #[ORM\Column(length: 32, enumType: InstitutionType::class)]
    private InstitutionType $type;

    #[ORM\Column(length: 32, enumType: InstitutionStatus::class)]
    private InstitutionStatus $status;

    #[ORM\Column(length: 16)]
    private string $locale;

    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        string $name,
        string $normalizedName,
        string $slug,
        InstitutionType $type,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->slug = $slug;
        $this->type = $type;
        $this->status = InstitutionStatus::Pending;
        $this->locale = 'tr_TR';
        $this->timezone = 'Europe/Istanbul';
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer InstitutionCreator
     */
    public static function create(
        string $name,
        string $normalizedName,
        string $slug,
        InstitutionType $type,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($name, $normalizedName, $slug, $type, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getType(): InstitutionType
    {
        return $this->type;
    }

    public function getStatus(): InstitutionStatus
    {
        return $this->status;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[Ignore]
    public function transitionTo(InstitutionStatus $target, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw InstitutionOperationException::invalidTransition();
        }
        $this->status = $target;
        $this->updatedAt = $now;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AcademicYearStatus;
use App\Exception\AcademicYearException;
use App\Repository\AcademicYearRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Institution-scoped academic year. Closed is terminal; at most one active year per institution (guard table).
 */
#[ORM\Entity(repositoryClass: AcademicYearRepository::class)]
#[ORM\Table(name: 'academic_years')]
#[ORM\UniqueConstraint(name: 'uniq_academic_year_institution_normalized_name', columns: ['institution_id', 'normalized_name'])]
#[ORM\Index(name: 'idx_academic_year_institution_status', columns: ['institution_id', 'status'])]
#[ORM\Index(name: 'idx_academic_year_institution_dates', columns: ['institution_id', 'starts_at', 'ends_at'])]
class AcademicYear
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Institution $institution;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(name: 'normalized_name', length: 180)]
    private string $normalizedName;

    #[ORM\Column(name: 'starts_at', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(length: 32, enumType: AcademicYearStatus::class)]
    private AcademicYearStatus $status;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        Institution $institution,
        string $name,
        string $normalizedName,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($startsAt > $endsAt) {
            throw AcademicYearException::invalidInput('startsAt must be on or before endsAt.');
        }

        $this->id = $id ?? new UuidV7();
        $this->institution = $institution;
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->status = AcademicYearStatus::Planned;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer AcademicYearManager
     */
    public static function createPlanned(
        Institution $institution,
        string $name,
        string $normalizedName,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($institution, $name, $normalizedName, $startsAt, $endsAt, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getInstitution(): Institution
    {
        return $this->institution;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getStatus(): AcademicYearStatus
    {
        return $this->status;
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
    public function activate(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(AcademicYearStatus::Active)) {
            throw AcademicYearException::invalidTransition();
        }
        $this->status = AcademicYearStatus::Active;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function close(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(AcademicYearStatus::Closed)) {
            throw AcademicYearException::invalidTransition();
        }
        $this->status = AcademicYearStatus::Closed;
        $this->updatedAt = $now;
    }
}

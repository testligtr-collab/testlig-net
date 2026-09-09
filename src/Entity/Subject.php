<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SubjectStatus;
use App\Exception\SubjectException;
use App\Repository\SubjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Platform-global subject catalog entry. Code is immutable after create.
 */
#[ORM\Entity(repositoryClass: SubjectRepository::class)]
#[ORM\Table(name: 'subjects')]
#[ORM\UniqueConstraint(name: 'uniq_subject_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_subject_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_subject_status', columns: ['status'])]
class Subject
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(name: 'normalized_name', length: 180)]
    private string $normalizedName;

    #[ORM\Column(length: 180)]
    private string $slug;

    #[ORM\Column(length: 32, enumType: SubjectStatus::class)]
    private SubjectStatus $status;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        string $code,
        string $name,
        string $normalizedName,
        string $slug,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->code = $code;
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->slug = $slug;
        $this->status = SubjectStatus::Active;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer SubjectManager
     */
    public static function create(
        string $code,
        string $name,
        string $normalizedName,
        string $slug,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($code, $name, $normalizedName, $slug, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
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

    public function getStatus(): SubjectStatus
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
    public function rename(string $name, string $normalizedName, string $slug, \DateTimeImmutable $now): void
    {
        if (SubjectStatus::Archived === $this->status) {
            throw SubjectException::subjectArchived();
        }
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->slug = $slug;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function archive(\DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(SubjectStatus::Archived)) {
            throw SubjectException::invalidTransition();
        }
        $this->status = SubjectStatus::Archived;
        $this->updatedAt = $now;
    }
}

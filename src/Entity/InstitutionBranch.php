<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InstitutionBranchKind;
use App\Enum\InstitutionBranchStatus;
use App\Exception\InstitutionOperationException;
use App\Repository\InstitutionBranchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One campus or branch belonging to exactly one institution.
 * Creating a row does not create a membership, role, or class.
 */
#[ORM\Entity(repositoryClass: InstitutionBranchRepository::class)]
#[ORM\Table(name: 'institution_branches')]
#[ORM\UniqueConstraint(name: 'uniq_institution_branch_code', columns: ['institution_id', 'normalized_code'])]
#[ORM\Index(name: 'idx_institution_branches_status', columns: ['status'])]
#[ORM\Index(name: 'idx_institution_branches_institution_status', columns: ['institution_id', 'status'])]
class InstitutionBranch
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Institution::class)]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Institution $institution;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(name: 'normalized_name', length: 180)]
    private string $normalizedName;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(name: 'normalized_code', length: 32)]
    private string $normalizedCode;

    #[ORM\Column(length: 32, enumType: InstitutionBranchKind::class)]
    private InstitutionBranchKind $kind;

    #[ORM\Column(length: 32, enumType: InstitutionBranchStatus::class)]
    private InstitutionBranchStatus $status;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        Institution $institution,
        string $name,
        string $normalizedName,
        string $code,
        string $normalizedCode,
        InstitutionBranchKind $kind,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->institution = $institution;
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->code = $code;
        $this->normalizedCode = $normalizedCode;
        $this->kind = $kind;
        $this->status = InstitutionBranchStatus::Pending;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @internal prefer InstitutionBranchCreator
     */
    public static function create(
        Institution $institution,
        string $name,
        string $normalizedName,
        string $code,
        string $normalizedCode,
        InstitutionBranchKind $kind,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($institution, $name, $normalizedName, $code, $normalizedCode, $kind, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

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

    public function getCode(): string
    {
        return $this->code;
    }

    public function getNormalizedCode(): string
    {
        return $this->normalizedCode;
    }

    public function getKind(): InstitutionBranchKind
    {
        return $this->kind;
    }

    public function getStatus(): InstitutionBranchStatus
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
    public function revise(
        string $name,
        string $normalizedName,
        string $code,
        string $normalizedCode,
        InstitutionBranchKind $kind,
        \DateTimeImmutable $now,
    ): void {
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->code = $code;
        $this->normalizedCode = $normalizedCode;
        $this->kind = $kind;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function transitionTo(InstitutionBranchStatus $target, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw InstitutionOperationException::invalidTransition();
        }
        $this->status = $target;
        $this->updatedAt = $now;
    }
}

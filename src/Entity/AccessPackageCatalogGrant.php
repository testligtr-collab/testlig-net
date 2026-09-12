<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccessPackageCatalogResourceKind;
use App\Enum\GradeLevel;
use App\Exception\AccessEntitlementException;
use App\Repository\AccessPackageCatalogGrantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Catalog-level grant. Learning content requires subject+grade; assessment uses grade only (Assessment has no subject).
 */
#[ORM\Entity(repositoryClass: AccessPackageCatalogGrantRepository::class)]
#[ORM\Table(name: 'access_package_catalog_grants')]
#[ORM\UniqueConstraint(name: 'uniq_apcg_id_version', columns: ['id', 'version_id'])]
#[ORM\Index(name: 'idx_apcg_version_kind', columns: ['version_id', 'resource_kind'])]
class AccessPackageCatalogGrant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'version_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AccessPackageVersion $version;

    #[ORM\Column(name: 'resource_kind', length: 32, enumType: AccessPackageCatalogResourceKind::class)]
    private AccessPackageCatalogResourceKind $resourceKind;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Subject $subject;

    #[ORM\Column(name: 'grade_level', enumType: GradeLevel::class)]
    private GradeLevel $gradeLevel;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        AccessPackageVersion $version,
        AccessPackageCatalogResourceKind $resourceKind,
        ?Subject $subject,
        GradeLevel $gradeLevel,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if (AccessPackageCatalogResourceKind::LearningContent === $resourceKind && null === $subject) {
            throw AccessEntitlementException::invalidInput('Learning content catalog grant requires subject.');
        }
        if (AccessPackageCatalogResourceKind::Assessment === $resourceKind && null !== $subject) {
            throw AccessEntitlementException::invalidInput('Assessment catalog grant must not set subject (Assessment has no subject).');
        }

        $this->id = $id ?? new UuidV7();
        $this->version = $version;
        $this->resourceKind = $resourceKind;
        $this->subject = $subject;
        $this->gradeLevel = $gradeLevel;
        $this->createdAt = $now;
    }

    /**
     * @internal prefer AccessPackageVersionManager
     */
    public static function create(
        AccessPackageVersion $version,
        AccessPackageCatalogResourceKind $resourceKind,
        ?Subject $subject,
        GradeLevel $gradeLevel,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($version, $resourceKind, $subject, $gradeLevel, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getVersion(): AccessPackageVersion
    {
        return $this->version;
    }

    public function getResourceKind(): AccessPackageCatalogResourceKind
    {
        return $this->resourceKind;
    }

    #[Ignore]
    public function getSubject(): ?Subject
    {
        return $this->subject;
    }

    public function getGradeLevel(): GradeLevel
    {
        return $this->gradeLevel;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccessPackageAssessmentGrantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: AccessPackageAssessmentGrantRepository::class)]
#[ORM\Table(name: 'access_package_assessment_grants')]
#[ORM\UniqueConstraint(name: 'uniq_apag_version_assessment', columns: ['version_id', 'assessment_id'])]
#[ORM\UniqueConstraint(name: 'uniq_apag_id_version', columns: ['id', 'version_id'])]
#[ORM\Index(name: 'idx_apag_assessment', columns: ['assessment_id'])]
class AccessPackageAssessmentGrant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'version_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AccessPackageVersion $version;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Assessment $assessment;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        AccessPackageVersion $version,
        Assessment $assessment,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->version = $version;
        $this->assessment = $assessment;
        $this->createdAt = $now;
    }

    /**
     * @internal prefer AccessPackageVersionManager
     */
    public static function create(
        AccessPackageVersion $version,
        Assessment $assessment,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($version, $assessment, $now, $id);
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

    #[Ignore]
    public function getAssessment(): Assessment
    {
        return $this->assessment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

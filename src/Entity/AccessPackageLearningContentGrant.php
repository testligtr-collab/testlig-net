<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccessPackageLearningContentGrantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: AccessPackageLearningContentGrantRepository::class)]
#[ORM\Table(name: 'access_package_learning_content_grants')]
#[ORM\UniqueConstraint(name: 'uniq_aplcg_version_content', columns: ['version_id', 'content_id'])]
#[ORM\UniqueConstraint(name: 'uniq_aplcg_id_version', columns: ['id', 'version_id'])]
#[ORM\Index(name: 'idx_aplcg_content', columns: ['content_id'])]
class AccessPackageLearningContentGrant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'version_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AccessPackageVersion $version;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'content_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private LearningContent $learningContent;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        AccessPackageVersion $version,
        LearningContent $learningContent,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->version = $version;
        $this->learningContent = $learningContent;
        $this->createdAt = $now;
    }

    /**
     * @internal prefer AccessPackageVersionManager
     */
    public static function create(
        AccessPackageVersion $version,
        LearningContent $learningContent,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($version, $learningContent, $now, $id);
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
    public function getLearningContent(): LearningContent
    {
        return $this->learningContent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

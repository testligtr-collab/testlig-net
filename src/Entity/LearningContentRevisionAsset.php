<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LearningContentRevisionAssetRole;
use App\Exception\LearningContentException;
use App\Repository\LearningContentRevisionAssetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Attachment of a stored media asset to a learning content revision.
 */
#[ORM\Entity(repositoryClass: LearningContentRevisionAssetRepository::class)]
#[ORM\Table(name: 'learning_content_revision_assets')]
#[ORM\UniqueConstraint(name: 'uniq_lcra_revision_asset_role', columns: ['revision_id', 'asset_id', 'role'])]
#[ORM\UniqueConstraint(name: 'uniq_lcra_id_revision', columns: ['id', 'revision_id'])]
#[ORM\Index(name: 'idx_lcra_revision', columns: ['revision_id'])]
#[ORM\Index(name: 'idx_lcra_asset', columns: ['asset_id'])]
class LearningContentRevisionAsset
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'revision_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private LearningContentRevision $revision;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'asset_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private StoredMediaAsset $asset;

    #[ORM\Column(length: 32, enumType: LearningContentRevisionAssetRole::class)]
    private LearningContentRevisionAssetRole $role;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(name: 'alt_text', length: 500, nullable: true)]
    private ?string $altText;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $caption;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        LearningContentRevision $revision,
        StoredMediaAsset $asset,
        LearningContentRevisionAssetRole $role,
        int $position,
        ?string $altText,
        ?string $caption,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        if ($revision->isSealed()) {
            throw LearningContentException::revisionSealed();
        }
        if ($position < 0) {
            throw LearningContentException::invalidInput('Asset position must be >= 0.');
        }

        $this->id = $id ?? new UuidV7();
        $this->revision = $revision;
        $this->asset = $asset;
        $this->role = $role;
        $this->position = $position;
        $this->altText = $altText;
        $this->caption = $caption;
        $this->createdAt = $now;
    }

    /**
     * @internal prefer LearningContentManager
     */
    public static function create(
        LearningContentRevision $revision,
        StoredMediaAsset $asset,
        LearningContentRevisionAssetRole $role,
        int $position,
        ?string $altText,
        ?string $caption,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ): self {
        return new self($revision, $asset, $role, $position, $altText, $caption, $now, $id);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getRevision(): LearningContentRevision
    {
        return $this->revision;
    }

    #[Ignore]
    public function getAsset(): StoredMediaAsset
    {
        return $this->asset;
    }

    public function getRole(): LearningContentRevisionAssetRole
    {
        return $this->role;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getAltText(): ?string
    {
        return $this->altText;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

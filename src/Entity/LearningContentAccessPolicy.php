<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ResourceAccessClass;
use App\Repository\LearningContentAccessPolicyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Separate from publication body. Default when first set: entitlement_required.
 */
#[ORM\Entity(repositoryClass: LearningContentAccessPolicyRepository::class)]
#[ORM\Table(name: 'learning_content_access_policies')]
class LearningContentAccessPolicy
{
    public const SCHEMA_VERSION = 1;

    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'content_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private LearningContent $learningContent;

    #[ORM\Column(name: 'access_class', length: 32, enumType: ResourceAccessClass::class)]
    private ResourceAccessClass $accessClass;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'set_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $setBy;

    #[ORM\Column(name: 'set_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $setAt;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion;

    private function __construct(
        LearningContent $learningContent,
        ResourceAccessClass $accessClass,
        User $setBy,
        \DateTimeImmutable $setAt,
        int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        $this->learningContent = $learningContent;
        $this->accessClass = $accessClass;
        $this->setBy = $setBy;
        $this->setAt = $setAt;
        $this->schemaVersion = $schemaVersion;
    }

    /**
     * @internal prefer AccessPackageManager / policy helpers
     */
    public static function create(
        LearningContent $learningContent,
        ResourceAccessClass $accessClass,
        User $setBy,
        \DateTimeImmutable $setAt,
        int $schemaVersion = self::SCHEMA_VERSION,
    ): self {
        return new self($learningContent, $accessClass, $setBy, $setAt, $schemaVersion);
    }

    public function update(ResourceAccessClass $accessClass, User $setBy, \DateTimeImmutable $setAt): void
    {
        $this->accessClass = $accessClass;
        $this->setBy = $setBy;
        $this->setAt = $setAt;
    }

    public function getContentId(): Uuid
    {
        return $this->learningContent->getId();
    }

    #[Ignore]
    public function getLearningContent(): LearningContent
    {
        return $this->learningContent;
    }

    public function getAccessClass(): ResourceAccessClass
    {
        return $this->accessClass;
    }

    #[Ignore]
    public function getSetBy(): User
    {
        return $this->setBy;
    }

    public function getSetAt(): \DateTimeImmutable
    {
        return $this->setAt;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }
}

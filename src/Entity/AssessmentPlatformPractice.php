<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssessmentPlatformPracticeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * One self-serve practice binding per student and platform assessment.
 *
 * The attempt itself remains AssessmentAttempt. This row only enforces V1
 * "one completed attempt" without blocking institutional deliveries of the same assessment.
 */
#[ORM\Entity(repositoryClass: AssessmentPlatformPracticeRepository::class)]
#[ORM\Table(name: 'assessment_platform_practices')]
#[ORM\UniqueConstraint(name: 'uniq_platform_practice_user_assessment', columns: ['user_id', 'assessment_id'])]
#[ORM\Index(name: 'idx_platform_practice_assessment', columns: ['assessment_id'])]
#[ORM\Index(name: 'idx_platform_practice_delivery', columns: ['delivery_id'])]
class AssessmentPlatformPractice
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assessment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Assessment $assessment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'delivery_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AssessmentDelivery $delivery;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        User $user,
        Assessment $assessment,
        AssessmentDelivery $delivery,
        \DateTimeImmutable $createdAt,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new UuidV7();
        $this->user = $user;
        $this->assessment = $assessment;
        $this->delivery = $delivery;
        $this->createdAt = $createdAt;
    }

    public static function create(
        User $user,
        Assessment $assessment,
        AssessmentDelivery $delivery,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self($user, $assessment, $delivery, $createdAt);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAssessment(): Assessment
    {
        return $this->assessment;
    }

    public function getDelivery(): AssessmentDelivery
    {
        return $this->delivery;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CurriculumAccessState;
use App\Exception\CurriculumException;
use App\Repository\CurriculumAccessGateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * Fixed stage and grade access. Keys are not created or deleted here.
 */
#[ORM\Entity(repositoryClass: CurriculumAccessGateRepository::class)]
#[ORM\Table(name: 'curriculum_access_gates')]
#[ORM\UniqueConstraint(name: 'uniq_curriculum_access_gate', columns: ['kind', 'gate_key'])]
class CurriculumAccessGate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(name: 'gate_key', length: 16)]
    private string $gateKey;

    #[ORM\Column(length: 80)]
    private string $label;

    #[ORM\Column(length: 32, enumType: CurriculumAccessState::class)]
    private CurriculumAccessState $status;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getGateKey(): string
    {
        return $this->gateKey;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getStatus(): CurriculumAccessState
    {
        return $this->status;
    }

    #[Ignore]
    public function rename(string $label, \DateTimeImmutable $now): void
    {
        $this->label = $label;
        $this->updatedAt = $now;
    }

    #[Ignore]
    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->transition(CurriculumAccessState::Inactive, $now);
    }

    #[Ignore]
    public function reactivate(\DateTimeImmutable $now): void
    {
        $this->transition(CurriculumAccessState::Active, $now);
    }

    private function transition(CurriculumAccessState $target, \DateTimeImmutable $now): void
    {
        if ($this->status === $target) {
            throw CurriculumException::invalidTransition();
        }
        $this->status = $target;
        $this->updatedAt = $now;
    }
}

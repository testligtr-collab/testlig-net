<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\ParentStudentLinkException;
use App\Repository\ParentStudentLinkActiveGuardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

/**
 * MariaDB-safe uniqueness for one active (pending|verified) link per parent+student pair.
 *
 * Composite primary key on the pair replaces a partial unique index. When a link is ended,
 * this guard row is removed so a new pending link for the same pair can be created while
 * ended history rows remain on {@see ParentStudentLink}.
 *
 * Future manager contract: delete this guard and call {@see ParentStudentLink::markEnded()}
 * in one transaction (never leave an ended link still guarded, or an active link unguarded
 * after a failed end).
 *
 * @internal prefer a future ParentStudentLinkManager
 */
#[ORM\Entity(repositoryClass: ParentStudentLinkActiveGuardRepository::class)]
#[ORM\Table(name: 'parent_student_link_active_guards')]
#[ORM\UniqueConstraint(name: 'uniq_psl_active_guard_link', columns: ['link_id'])]
class ParentStudentLinkActiveGuard
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'parent_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $parent;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $student;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'link_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ParentStudentLink $link;

    private function __construct(User $parent, User $student, ParentStudentLink $link)
    {
        $this->parent = $parent;
        $this->student = $student;
        $this->link = $link;
    }

    /**
     * @internal prefer a future ParentStudentLinkManager
     */
    public static function bind(ParentStudentLink $link): self
    {
        if (!$link->isActivePairOccupant()) {
            throw ParentStudentLinkException::invalidInput();
        }

        $parent = $link->getParent();
        $student = $link->getStudent();
        if ($parent->getId()->equals($student->getId())) {
            throw ParentStudentLinkException::invalidInput();
        }

        return new self($parent, $student, $link);
    }

    public function getParentUserId(): Uuid
    {
        return $this->parent->getId();
    }

    public function getStudentUserId(): Uuid
    {
        return $this->student->getId();
    }

    #[Ignore]
    public function getParent(): User
    {
        return $this->parent;
    }

    #[Ignore]
    public function getStudent(): User
    {
        return $this->student;
    }

    #[Ignore]
    public function getLink(): ParentStudentLink
    {
        return $this->link;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HomeworkRecipientRepository;
use App\Time\UtcInstant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Historical recipient snapshot at assign time. Not by itself current student access.
 *
 * @internal prefer HomeworkAssignmentManager
 */
#[ORM\Entity(repositoryClass: HomeworkRecipientRepository::class)]
#[ORM\Table(name: 'homework_recipients')]
#[ORM\UniqueConstraint(name: 'uniq_homework_recipient_assignment_student', columns: ['assignment_id', 'student_user_id'])]
#[ORM\Index(name: 'idx_homework_rcp_student', columns: ['student_user_id'])]
#[ORM\Index(name: 'idx_homework_rcp_membership', columns: ['student_membership_id'])]
#[ORM\Index(name: 'idx_homework_rcp_enrollment', columns: ['enrollment_id'])]
class HomeworkRecipient
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assignment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private HomeworkAssignment $assignment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $studentUser;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'student_membership_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InstitutionMembership $studentMembership;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'enrollment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ClassroomStudentEnrollment $enrollment;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        HomeworkAssignment $assignment,
        ClassroomStudentEnrollment $enrollment,
        \DateTimeImmutable $now,
    ) {
        $membership = $enrollment->getStudentMembership();
        $this->id = new UuidV7();
        $this->assignment = $assignment;
        $this->studentUser = $membership->getUser();
        $this->studentMembership = $membership;
        $this->enrollment = $enrollment;
        $this->createdAt = UtcInstant::ensure($now);
    }

    /**
     * @internal prefer HomeworkAssignmentManager
     */
    public static function fromEnrollment(
        HomeworkAssignment $assignment,
        ClassroomStudentEnrollment $enrollment,
        \DateTimeImmutable $now,
    ): self {
        return new self($assignment, $enrollment, $now);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[Ignore]
    public function getAssignment(): HomeworkAssignment
    {
        return $this->assignment;
    }

    #[Ignore]
    public function getStudentUser(): User
    {
        return $this->studentUser;
    }

    #[Ignore]
    public function getStudentMembership(): InstitutionMembership
    {
        return $this->studentMembership;
    }

    #[Ignore]
    public function getEnrollment(): ClassroomStudentEnrollment
    {
        return $this->enrollment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return UtcInstant::ensure($this->createdAt);
    }
}

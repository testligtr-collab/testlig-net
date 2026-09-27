<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InstitutionStudentInvitePendingGuardRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One pending student invite per institution, academic year, and normalized email.
 * Removed when the invite is consumed, revoked, or replaced after expiry.
 */
#[ORM\Entity(repositoryClass: InstitutionStudentInvitePendingGuardRepository::class)]
#[ORM\Table(name: 'institution_student_invite_pending_guards')]
#[ORM\UniqueConstraint(name: 'uniq_student_invite_pending_invitation', columns: ['invitation_id'])]
#[ORM\Index(name: 'idx_student_invite_guard_year', columns: ['academic_year_id'])]
class InstitutionStudentInvitePendingGuard
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Institution $institution;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AcademicYear $academicYear;

    #[ORM\Id]
    #[ORM\Column(name: 'normalized_email', length: 180)]
    private string $normalizedEmail;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'invitation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InstitutionStudentInvitation $invitation;

    public function __construct(
        Institution $institution,
        AcademicYear $academicYear,
        string $normalizedEmail,
        InstitutionStudentInvitation $invitation,
    ) {
        $this->institution = $institution;
        $this->academicYear = $academicYear;
        $this->normalizedEmail = $normalizedEmail;
        $this->invitation = $invitation;
    }

    public function getInvitation(): InstitutionStudentInvitation
    {
        return $this->invitation;
    }
}

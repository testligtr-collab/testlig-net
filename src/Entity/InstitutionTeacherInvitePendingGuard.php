<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InstitutionTeacherInvitePendingGuardRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One pending teacher invite per institution and normalized email.
 * Removed when the invite is consumed, revoked, or replaced after expiry.
 */
#[ORM\Entity(repositoryClass: InstitutionTeacherInvitePendingGuardRepository::class)]
#[ORM\Table(name: 'institution_teacher_invite_pending_guards')]
#[ORM\UniqueConstraint(name: 'uniq_teacher_invite_pending_invitation', columns: ['invitation_id'])]
class InstitutionTeacherInvitePendingGuard
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'institution_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Institution $institution;

    #[ORM\Id]
    #[ORM\Column(name: 'normalized_email', length: 180)]
    private string $normalizedEmail;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'invitation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InstitutionTeacherInvitation $invitation;

    public function __construct(Institution $institution, string $normalizedEmail, InstitutionTeacherInvitation $invitation)
    {
        $this->institution = $institution;
        $this->normalizedEmail = $normalizedEmail;
        $this->invitation = $invitation;
    }

    public function getInstitution(): Institution
    {
        return $this->institution;
    }

    public function getNormalizedEmail(): string
    {
        return $this->normalizedEmail;
    }

    public function getInvitation(): InstitutionTeacherInvitation
    {
        return $this->invitation;
    }
}

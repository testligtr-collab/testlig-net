<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\TeacherApplication;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\UserStatus;
use App\Service\Admin\AdminShortRef;
use Symfony\Component\Uid\Uuid;

final class AdminTeacherApplicationRow
{
    /**
     * @param list<string> $globalRoles
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly string $shortRef,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly UserStatus $accountStatus,
        public readonly array $globalRoles,
        public readonly bool $emailVerified,
        public readonly \DateTimeImmutable $accountCreatedAt,
        public readonly OnboardingApplicationStatus $status,
        public readonly \DateTimeImmutable $submittedAt,
        public readonly ?string $decisionReasonCode,
        public readonly ?\DateTimeImmutable $decidedAt,
    ) {
    }

    public static function fromApplication(TeacherApplication $application): self
    {
        $user = $application->getUser();

        return new self(
            $application->getId(),
            AdminShortRef::fromUuid($application->getId()),
            $user->getFirstName(),
            $user->getLastName(),
            $user->getEmail(),
            $user->getStatus(),
            $user->getRoles(),
            null !== $user->getEmailVerifiedAt(),
            $user->getCreatedAt(),
            $application->getStatus(),
            $application->getSubmittedAt(),
            $application->getDecisionReasonCode(),
            $application->getDecidedAt(),
        );
    }

    public function displayName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    public function initials(): string
    {
        $first = mb_substr(trim($this->firstName), 0, 1, 'UTF-8');
        $last = mb_substr(trim($this->lastName), 0, 1, 'UTF-8');
        $initials = mb_strtoupper($first.$last, 'UTF-8');

        return '' !== $initials ? $initials : '?';
    }

    public function avatarTone(): string
    {
        $tones = ['blue', 'green', 'violet', 'amber', 'teal', 'rose'];
        $hex = substr(str_replace('-', '', $this->id->toRfc4122()), 0, 2);
        $index = ((int) hexdec($hex)) % \count($tones);

        return $tones[$index];
    }

    public function holdsTeacherRole(): bool
    {
        return \in_array('ROLE_TEACHER', $this->globalRoles, true)
            || \in_array('ROLE_EXPERT_TEACHER', $this->globalRoles, true)
            || \in_array('ROLE_HEAD_TEACHER', $this->globalRoles, true);
    }
}

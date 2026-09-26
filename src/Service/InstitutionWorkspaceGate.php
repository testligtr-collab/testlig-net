<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\InstitutionWorkspaceDecision;
use App\Dto\InstitutionWorkspaceOption;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\InstitutionMembershipRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the institution workspace from active owner/manager membership.
 * Global roles, including SuperAdmin, do not open another institution.
 */
final class InstitutionWorkspaceGate
{
    public const SESSION_KEY = '_institution_workspace';

    public const PANEL = 'panel';

    public const CHOOSE = 'choose';

    public const ONBOARDING = 'onboarding';

    public const DENIED = 'denied';

    public function __construct(
        private readonly InstitutionMembershipRepository $memberships,
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function resolve(User $user): InstitutionWorkspaceDecision
    {
        $rows = $this->memberships->findActiveLeadership($user);
        $sessionId = $this->sessionInstitutionId();
        $selected = $this->matchSession($rows, $sessionId);
        if (null !== $sessionId && !$selected instanceof InstitutionMembership) {
            $session = $this->requestStack->getCurrentRequest()?->getSession();
            $session?->remove(self::SESSION_KEY);
        }
        if (!$selected instanceof InstitutionMembership && 1 === \count($rows)) {
            $selected = $rows[0];
        }

        $options = [];
        foreach ($rows as $membership) {
            $options[] = new InstitutionWorkspaceOption(
                $this->hasher->workspaceReference('institution', $membership->getInstitution()->getId()),
                $membership->getInstitution()->getName(),
                self::roleLabel($membership->getRole()->value),
                $membership->getRole(),
            );
        }

        if ($selected instanceof InstitutionMembership) {
            return new InstitutionWorkspaceDecision(self::PANEL, $options, $selected);
        }
        if ([] !== $rows) {
            return new InstitutionWorkspaceDecision(self::CHOOSE, $options, null);
        }
        if ($this->memberships->hasAnyMembership($user) || $this->deniesWithoutMembership($user)) {
            return new InstitutionWorkspaceDecision(self::DENIED, [], null);
        }

        return new InstitutionWorkspaceDecision(self::ONBOARDING, [], null);
    }

    public function select(User $user, string $reference): bool
    {
        $reference = strtolower(trim($reference));
        if (1 !== preg_match('/^[0-9a-f]{20}$/', $reference)) {
            return false;
        }
        foreach ($this->memberships->findActiveLeadership($user) as $membership) {
            $candidate = $this->hasher->workspaceReference('institution', $membership->getInstitution()->getId());
            if (hash_equals($candidate, $reference)) {
                $this->requestStack->getSession()->set(self::SESSION_KEY, $membership->getInstitution()->getId()->toRfc4122());

                return true;
            }
        }

        return false;
    }

    /**
     * @param list<InstitutionMembership> $rows
     */
    private function matchSession(array $rows, ?string $sessionId): ?InstitutionMembership
    {
        if (null === $sessionId) {
            return null;
        }
        foreach ($rows as $membership) {
            if (hash_equals($membership->getInstitution()->getId()->toRfc4122(), $sessionId)) {
                return $membership;
            }
        }

        return null;
    }

    private function sessionInstitutionId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return null;
        }
        $session = $request->getSession();
        if (!$session->has(self::SESSION_KEY)) {
            return null;
        }
        $value = $session->get(self::SESSION_KEY);
        if (!\is_string($value) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            $session->remove(self::SESSION_KEY);

            return null;
        }

        return strtolower($value);
    }

    private function deniesWithoutMembership(User $user): bool
    {
        foreach ([
            UserRole::Student,
            UserRole::Parent,
            UserRole::Moderator,
            UserRole::Admin,
            UserRole::SuperAdmin,
            UserRole::Teacher,
            UserRole::ExpertTeacher,
            UserRole::HeadTeacher,
            UserRole::InstitutionManager,
        ] as $role) {
            if (\in_array($role->value, $user->getRoles(), true)) {
                return true;
            }
        }

        return false;
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'owner' => 'Kurum sahibi',
            'manager' => 'Yönetici',
            'teacher' => 'Öğretmen',
            'staff' => 'Personel',
            'student' => 'Öğrenci',
            default => 'Üye',
        };
    }
}

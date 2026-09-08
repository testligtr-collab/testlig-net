<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\InstitutionType;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InstitutionOperationException;
use App\Repository\InstitutionMembershipRepository;
use App\Repository\InstitutionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Creates institutions with an initial owner membership. SUPER_ADMIN only in this stage.
 */
final class InstitutionCreator
{
    public function __construct(
        private readonly InstitutionRepository $institutions,
        private readonly InstitutionMembershipRepository $memberships,
        private readonly InstitutionNameNormalizer $nameNormalizer,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(
        User $actor,
        User $owner,
        string $name,
        InstitutionType $type,
        string $reasonCode,
    ): Institution {
        $this->assertSuperAdmin($actor);
        $this->assertActiveVerifiedUser($owner);
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $names = $this->nameNormalizer->normalize($name);

        if ($this->institutions->existsWithSlug($names['slug'])) {
            throw InstitutionOperationException::conflict();
        }

        try {
            return $this->entityManager->wrapInTransaction(function () use ($actor, $owner, $names, $type, $reasonCode): Institution {
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $institution = Institution::create(
                    name: $names['name'],
                    normalizedName: $names['normalizedName'],
                    slug: $names['slug'],
                    type: $type,
                    now: $now,
                );
                $this->institutions->save($institution, false);

                $membership = InstitutionMembership::createActive(
                    institution: $institution,
                    user: $owner,
                    role: \App\Enum\InstitutionMembershipRole::Owner,
                    now: $now,
                );
                $this->memberships->save($membership, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::InstitutionCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $actor,
                    subjectUser: $owner,
                    metadata: [
                        'source' => 'institution_creator',
                        'reason_code' => $reasonCode,
                        'institution_id' => $institution->getId()->toRfc4122(),
                        'institution_type' => $type->value,
                        'membership_role' => $membership->getRole()->value,
                        'new_status' => $institution->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::InstitutionMemberAdded,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $actor,
                    subjectUser: $owner,
                    metadata: [
                        'source' => 'institution_creator',
                        'reason_code' => $reasonCode,
                        'institution_id' => $institution->getId()->toRfc4122(),
                        'membership_role' => $membership->getRole()->value,
                        'new_status' => $membership->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $institution;
            });
        } catch (UniqueConstraintViolationException) {
            throw InstitutionOperationException::conflict();
        }
    }

    private function assertSuperAdmin(User $actor): void
    {
        if (!\in_array(UserRole::SuperAdmin->value, $actor->getRoles(), true)) {
            throw InstitutionOperationException::unauthorized();
        }
    }

    private function assertActiveVerifiedUser(User $user): void
    {
        if (UserStatus::Active !== $user->getStatus() || null === $user->getEmailVerifiedAt()) {
            throw InstitutionOperationException::invalidInput('Owner must be an active verified user.');
        }
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw InstitutionOperationException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}

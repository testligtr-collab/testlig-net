<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\EntitlementAccessDecision;
use App\Access\EntitlementAuthorizationProjector;
use App\Access\EntitlementGrantGraph;
use App\Access\EntitlementLicenseAuthSnapshot;
use App\Access\EntitlementSeatAuthSnapshot;
use App\Entity\Assessment;
use App\Entity\AssessmentAccessPolicy;
use App\Entity\LearningContent;
use App\Entity\LearningContentAccessPolicy;
use App\Entity\User;
use App\Enum\AssessmentStatus;
use App\Enum\EntitlementAccessDecisionReason;
use App\Enum\EntitlementGrantSource;
use App\Enum\LearningContentStatus;
use App\Enum\ResourceAccessClass;
use App\Enum\UserStatus;
use App\Exception\AccessEntitlementException;
use App\Repository\AssessmentAccessPolicyRepository;
use App\Repository\LearningContentAccessPolicyRepository;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Fail-closed entitlement evaluation for published learning content and assessments.
 *
 * Access-time policy integrity:
 * 1) load license/package/version via DBAL snapshot
 * 2) load grant graph via fresh DBAL queries (no managed collections)
 * 3) recompute canonical hash and triple hash_equals
 *    (fresh ↔ version.policyHash, fresh ↔ license.policySnapshotHash, version ↔ license)
 *
 * Seat/membership/institution chain also uses DBAL snapshots (no identity-map associations).
 *
 * High-volume allow decisions are NOT audited per view (mutations audit only).
 */
final class EntitlementAccessGate
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LearningContentAccessPolicyRepository $learningContentPolicies,
        private readonly AssessmentAccessPolicyRepository $assessmentPolicies,
        private readonly EntitlementAuthorizationProjector $projector,
        private readonly ClockInterface $clock,
    ) {
    }

    public function evaluateLearningContent(Uuid $contentId, User $actor): EntitlementAccessDecision
    {
        try {
            return $this->doEvaluateLearningContent($contentId, $actor);
        } catch (DeadlockException|LockWaitTimeoutException) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::IntegrityFailed,
                $this->utcNow(),
                'learning_content',
                $contentId->toRfc4122(),
            );
        }
    }

    public function evaluateAssessment(Uuid $assessmentId, User $actor): EntitlementAccessDecision
    {
        try {
            return $this->doEvaluateAssessment($assessmentId, $actor);
        } catch (DeadlockException|LockWaitTimeoutException) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::IntegrityFailed,
                $this->utcNow(),
                'assessment',
                $assessmentId->toRfc4122(),
            );
        }
    }

    private function doEvaluateLearningContent(Uuid $contentId, User $actor): EntitlementAccessDecision
    {
        $now = $this->utcNow();
        $resourceType = 'learning_content';
        $resourceId = $contentId->toRfc4122();

        $freshUser = $this->requireFreshActiveVerifiedUser($actor, $now, $resourceType, $resourceId);
        if ($freshUser instanceof EntitlementAccessDecision) {
            return $freshUser;
        }

        $content = $this->findFreshLearningContent($contentId);
        if (!$content instanceof LearningContent) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::ResourceNotFound,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if (LearningContentStatus::Published !== $content->getStatus()) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::ResourceNotPublished,
                $now,
                $resourceType,
                $resourceId,
            );
        }

        $policy = $this->learningContentPolicies->findForContent($contentId);
        if (!$policy instanceof LearningContentAccessPolicy) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::AccessPolicyNotConfigured,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if (ResourceAccessClass::Free === $policy->getAccessClass()) {
            return EntitlementAccessDecision::allowed(
                EntitlementGrantSource::FreePublication,
                $now,
                $resourceType,
                $resourceId,
            );
        }

        $subjectId = $content->getSubject()->getId()->toRfc4122();
        $grade = $content->getGradeLevel()->value;

        return $this->evaluateLicensesForResource(
            $freshUser,
            $now,
            $resourceType,
            $resourceId,
            static function (EntitlementGrantGraph $graph) use ($resourceId, $subjectId, $grade): bool {
                return $graph->coversLearningContent($resourceId, $subjectId, $grade);
            },
        );
    }

    private function doEvaluateAssessment(Uuid $assessmentId, User $actor): EntitlementAccessDecision
    {
        $now = $this->utcNow();
        $resourceType = 'assessment';
        $resourceId = $assessmentId->toRfc4122();

        $freshUser = $this->requireFreshActiveVerifiedUser($actor, $now, $resourceType, $resourceId);
        if ($freshUser instanceof EntitlementAccessDecision) {
            return $freshUser;
        }

        $assessment = $this->findFreshAssessment($assessmentId);
        if (!$assessment instanceof Assessment) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::ResourceNotFound,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if (AssessmentStatus::Published !== $assessment->getStatus()) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::ResourceNotPublished,
                $now,
                $resourceType,
                $resourceId,
            );
        }

        $policy = $this->assessmentPolicies->findForAssessment($assessmentId);
        if (!$policy instanceof AssessmentAccessPolicy) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::AccessPolicyNotConfigured,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if (ResourceAccessClass::Free === $policy->getAccessClass()) {
            return EntitlementAccessDecision::allowed(
                EntitlementGrantSource::FreePublication,
                $now,
                $resourceType,
                $resourceId,
            );
        }

        $grade = $assessment->getGradeLevel()->value;

        return $this->evaluateLicensesForResource(
            $freshUser,
            $now,
            $resourceType,
            $resourceId,
            static function (EntitlementGrantGraph $graph) use ($resourceId, $grade): bool {
                return $graph->coversAssessment($resourceId, $grade);
            },
        );
    }

    private function requireFreshActiveVerifiedUser(
        User $actor,
        \DateTimeImmutable $now,
        string $resourceType,
        string $resourceId,
    ): User|EntitlementAccessDecision {
        $freshUser = $this->findFreshUser($actor->getId());
        if (!$freshUser instanceof User) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::AuthenticationRequired,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if (UserStatus::Active !== $freshUser->getStatus()) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::UserNotActive,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if (null === $freshUser->getEmailVerifiedAt()) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::EmailNotVerified,
                $now,
                $resourceType,
                $resourceId,
            );
        }

        return $freshUser;
    }

    /**
     * @param callable(EntitlementGrantGraph): bool $covers
     */
    private function evaluateLicensesForResource(
        User $freshUser,
        \DateTimeImmutable $now,
        string $resourceType,
        string $resourceId,
        callable $covers,
    ): EntitlementAccessDecision {
        $userId = $freshUser->getId();

        $sawIntegrityFailure = false;
        $preferDeny = null;

        foreach ($this->projector->findUserLicenseCandidateIds($userId) as $licenseId) {
            $snapshot = $this->projector->loadLicenseSnapshot(Uuid::fromString($licenseId));
            if (!$snapshot instanceof EntitlementLicenseAuthSnapshot) {
                continue;
            }
            if ('user' !== $snapshot->licenseeType || $snapshot->userId !== $userId->toRfc4122()) {
                continue;
            }
            $decision = $this->tryLicenseSnapshot(
                $snapshot,
                $covers,
                $now,
                $resourceType,
                $resourceId,
                EntitlementGrantSource::IndividualLicense,
            );
            if ($decision->granted) {
                return $decision;
            }
            if (EntitlementAccessDecisionReason::IntegrityFailed === $decision->reason) {
                $sawIntegrityFailure = true;
            } elseif (null === $preferDeny && \in_array($decision->reason, [
                EntitlementAccessDecisionReason::LicenseSuspended,
                EntitlementAccessDecisionReason::LicenseRevoked,
                EntitlementAccessDecisionReason::LicenseExpired,
                EntitlementAccessDecisionReason::LicenseNotStarted,
                EntitlementAccessDecisionReason::LicenseNotActive,
            ], true)) {
                $preferDeny = $decision;
            }
        }

        foreach ($this->projector->findSeatCandidateIdsForUser($userId) as $seatId) {
            $seat = $this->projector->loadSeatSnapshot(Uuid::fromString($seatId));
            if (!$seat instanceof EntitlementSeatAuthSnapshot) {
                continue;
            }
            $seatDecision = $this->evaluateSeatChain($seat, $userId, $now, $resourceType, $resourceId);
            if ($seatDecision instanceof EntitlementAccessDecision) {
                if (null === $preferDeny) {
                    $preferDeny = $seatDecision;
                }
                continue;
            }
            $decision = $this->tryLicenseSnapshot(
                $seat->license,
                $covers,
                $now,
                $resourceType,
                $resourceId,
                EntitlementGrantSource::InstitutionLicenseSeat,
            );
            if ($decision->granted) {
                return $decision;
            }
            if (EntitlementAccessDecisionReason::IntegrityFailed === $decision->reason) {
                $sawIntegrityFailure = true;
            } elseif (null === $preferDeny && \in_array($decision->reason, [
                EntitlementAccessDecisionReason::LicenseSuspended,
                EntitlementAccessDecisionReason::LicenseRevoked,
                EntitlementAccessDecisionReason::LicenseExpired,
                EntitlementAccessDecisionReason::LicenseNotStarted,
                EntitlementAccessDecisionReason::LicenseNotActive,
                EntitlementAccessDecisionReason::SeatRequired,
            ], true)) {
                $preferDeny = $decision;
            }
        }

        if ($sawIntegrityFailure) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::IntegrityFailed,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if ($preferDeny instanceof EntitlementAccessDecision) {
            return $preferDeny;
        }

        return EntitlementAccessDecision::denied(
            EntitlementAccessDecisionReason::EntitlementRequired,
            $now,
            $resourceType,
            $resourceId,
        );
    }

    /**
     * @return EntitlementAccessDecision|null null when seat chain is valid
     */
    private function evaluateSeatChain(
        EntitlementSeatAuthSnapshot $seat,
        Uuid $actorUserId,
        \DateTimeImmutable $now,
        string $resourceType,
        string $resourceId,
    ): ?EntitlementAccessDecision {
        if ('active' !== $seat->seatStatus) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::SeatRevoked,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if ($seat->seatUserId !== $actorUserId->toRfc4122()) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::ScopeMismatch,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if ($seat->membershipUserId !== $seat->seatUserId
            || $seat->membershipInstitutionId !== $seat->seatInstitutionId
        ) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::ScopeMismatch,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if ('active' !== $seat->membershipStatus) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::MembershipNotActive,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if ('active' !== $seat->institutionStatus) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::InstitutionNotActive,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if ('institution' !== $seat->license->licenseeType) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::ScopeMismatch,
                $now,
                $resourceType,
                $resourceId,
            );
        }
        if ($seat->license->institutionId !== $seat->seatInstitutionId) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::ScopeMismatch,
                $now,
                $resourceType,
                $resourceId,
            );
        }

        return null;
    }

    /**
     * @param callable(EntitlementGrantGraph): bool $covers
     */
    private function tryLicenseSnapshot(
        EntitlementLicenseAuthSnapshot $license,
        callable $covers,
        \DateTimeImmutable $now,
        string $resourceType,
        string $resourceId,
        EntitlementGrantSource $grantSource,
    ): EntitlementAccessDecision {
        if ('suspended' === $license->licenseStatus) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::LicenseSuspended, $now, $resourceType, $resourceId);
        }
        if ('revoked' === $license->licenseStatus) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::LicenseRevoked, $now, $resourceType, $resourceId);
        }
        if ('expired' === $license->licenseStatus) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::LicenseExpired, $now, $resourceType, $resourceId);
        }
        if ('active' !== $license->licenseStatus) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::LicenseNotActive, $now, $resourceType, $resourceId);
        }
        if ($now < $license->validFrom) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::LicenseNotStarted, $now, $resourceType, $resourceId);
        }
        if ($now >= $license->validUntil) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::LicenseExpired, $now, $resourceType, $resourceId);
        }

        $graph = $this->projector->loadGrantGraph(Uuid::fromString($license->versionId));
        try {
            $this->projector->assertPolicyHashesIntact($license, $graph);
        } catch (AccessEntitlementException) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::IntegrityFailed,
                $now,
                $resourceType,
                $resourceId,
            );
        }

        if (!$covers($graph)) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::EntitlementRequired,
                $now,
                $resourceType,
                $resourceId,
            );
        }

        return EntitlementAccessDecision::allowed(
            $grantSource,
            $now,
            $resourceType,
            $resourceId,
            $license->licenseId,
            $license->packageId,
            $license->versionId,
            $license->versionNumber,
            $license->validUntil,
        );
    }

    private function findFreshLearningContent(Uuid $id): ?LearningContent
    {
        $entity = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(LearningContent::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity instanceof LearningContent ? $entity : null;
    }

    private function findFreshAssessment(Uuid $id): ?Assessment
    {
        $entity = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity instanceof Assessment ? $entity : null;
    }

    private function findFreshUser(Uuid $id): ?User
    {
        $entity = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity instanceof User ? $entity : null;
    }

    private function utcNow(): \DateTimeImmutable
    {
        return UtcInstant::ensure(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}

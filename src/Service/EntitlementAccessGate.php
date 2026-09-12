<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\EntitlementAccessDecision;
use App\Entity\AccessLicense;
use App\Entity\AccessPackageVersion;
use App\Entity\Assessment;
use App\Entity\AssessmentAccessPolicy;
use App\Entity\LearningContent;
use App\Entity\LearningContentAccessPolicy;
use App\Entity\User;
use App\Enum\AccessLicenseStatus;
use App\Enum\AccessPackageCatalogResourceKind;
use App\Enum\AssessmentStatus;
use App\Enum\EntitlementAccessDecisionReason;
use App\Enum\EntitlementGrantSource;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\LearningContentStatus;
use App\Enum\ResourceAccessClass;
use App\Enum\UserStatus;
use App\Repository\AccessLicenseRepository;
use App\Repository\AccessPackageAssessmentGrantRepository;
use App\Repository\AccessPackageCatalogGrantRepository;
use App\Repository\AccessPackageLearningContentGrantRepository;
use App\Repository\AssessmentAccessPolicyRepository;
use App\Repository\InstitutionLicenseSeatRepository;
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
 * Priority:
 * 1) actor active + email verified
 * 2) resource published
 * 3) access policy free → allowed (free_publication)
 * 4) individual active license covering resource (direct or catalog grant)
 * 5) institution license via active seat covering resource
 * 6) otherwise entitlement_required
 *
 * High-volume allow decisions are NOT audited per view (mutations audit only).
 * AssessmentDeliveryAccessGate classroom delivery windows remain separate from package entitlement.
 */
final class EntitlementAccessGate
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LearningContentAccessPolicyRepository $learningContentPolicies,
        private readonly AssessmentAccessPolicyRepository $assessmentPolicies,
        private readonly AccessLicenseRepository $licenses,
        private readonly InstitutionLicenseSeatRepository $seats,
        private readonly AccessPackageLearningContentGrantRepository $learningContentGrants,
        private readonly AccessPackageAssessmentGrantRepository $assessmentGrants,
        private readonly AccessPackageCatalogGrantRepository $catalogGrants,
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

        $auth = $this->evaluateActor($actor, $now, $resourceType, $resourceId);
        if (null !== $auth) {
            return $auth;
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

        return $this->evaluateLicensesForResource(
            $actor,
            $now,
            $resourceType,
            $resourceId,
            function (AccessPackageVersion $version) use ($content): bool {
                return $this->versionCoversLearningContent($version, $content);
            },
        );
    }

    private function doEvaluateAssessment(Uuid $assessmentId, User $actor): EntitlementAccessDecision
    {
        $now = $this->utcNow();
        $resourceType = 'assessment';
        $resourceId = $assessmentId->toRfc4122();

        $auth = $this->evaluateActor($actor, $now, $resourceType, $resourceId);
        if (null !== $auth) {
            return $auth;
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

        return $this->evaluateLicensesForResource(
            $actor,
            $now,
            $resourceType,
            $resourceId,
            function (AccessPackageVersion $version) use ($assessment): bool {
                return $this->versionCoversAssessment($version, $assessment);
            },
        );
    }

    private function evaluateActor(
        User $actor,
        \DateTimeImmutable $now,
        string $resourceType,
        string $resourceId,
    ): ?EntitlementAccessDecision {
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

        return null;
    }

    /**
     * @param callable(AccessPackageVersion): bool $covers
     */
    private function evaluateLicensesForResource(
        User $actor,
        \DateTimeImmutable $now,
        string $resourceType,
        string $resourceId,
        callable $covers,
    ): EntitlementAccessDecision {
        $freshUser = $this->findFreshUser($actor->getId());
        if (!$freshUser instanceof User) {
            return EntitlementAccessDecision::denied(
                EntitlementAccessDecisionReason::AuthenticationRequired,
                $now,
                $resourceType,
                $resourceId,
            );
        }

        foreach ($this->licenses->findActiveUserLicenses($freshUser->getId()) as $license) {
            $decision = $this->tryLicense($license, $covers, $now, $resourceType, $resourceId, EntitlementGrantSource::IndividualLicense);
            if ($decision->granted) {
                return $decision;
            }
        }

        foreach ($this->seats->findActiveForUser($freshUser->getId()) as $seat) {
            $membership = $seat->getMembership();
            if (InstitutionMembershipStatus::Active !== $membership->getStatus()) {
                continue;
            }
            $institution = $seat->getInstitution();
            if (InstitutionStatus::Active !== $institution->getStatus()) {
                continue;
            }

            $license = $seat->getLicense();
            $decision = $this->tryLicense(
                $license,
                $covers,
                $now,
                $resourceType,
                $resourceId,
                EntitlementGrantSource::InstitutionLicenseSeat,
            );
            if ($decision->granted) {
                return $decision;
            }
        }

        return EntitlementAccessDecision::denied(
            EntitlementAccessDecisionReason::EntitlementRequired,
            $now,
            $resourceType,
            $resourceId,
        );
    }

    /**
     * @param callable(AccessPackageVersion): bool $covers
     */
    private function tryLicense(
        AccessLicense $license,
        callable $covers,
        \DateTimeImmutable $now,
        string $resourceType,
        string $resourceId,
        EntitlementGrantSource $grantSource,
    ): EntitlementAccessDecision {
        if (AccessLicenseStatus::Suspended === $license->getStatus()) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::LicenseSuspended, $now, $resourceType, $resourceId);
        }
        if (AccessLicenseStatus::Revoked === $license->getStatus()) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::LicenseRevoked, $now, $resourceType, $resourceId);
        }
        if (AccessLicenseStatus::Expired === $license->getStatus()) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::LicenseExpired, $now, $resourceType, $resourceId);
        }
        if (AccessLicenseStatus::Active !== $license->getStatus()) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::LicenseNotActive, $now, $resourceType, $resourceId);
        }
        if ($now < $license->getValidFrom()) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::LicenseNotStarted, $now, $resourceType, $resourceId);
        }
        if ($now >= $license->getValidUntil()) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::LicenseExpired, $now, $resourceType, $resourceId);
        }

        $version = $license->getPackageVersion();
        if (!hash_equals($version->getPolicyHash(), $license->getPolicySnapshotHash())) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::IntegrityFailed, $now, $resourceType, $resourceId);
        }
        if (!$covers($version)) {
            return EntitlementAccessDecision::denied(EntitlementAccessDecisionReason::EntitlementRequired, $now, $resourceType, $resourceId);
        }

        return EntitlementAccessDecision::allowed(
            $grantSource,
            $now,
            $resourceType,
            $resourceId,
            $license->getId()->toRfc4122(),
            $license->getPackage()->getId()->toRfc4122(),
            $version->getId()->toRfc4122(),
            $version->getVersionNumber(),
            $license->getValidUntil(),
        );
    }

    private function versionCoversLearningContent(AccessPackageVersion $version, LearningContent $content): bool
    {
        foreach ($this->learningContentGrants->findByVersion($version) as $grant) {
            if ($grant->getLearningContent()->getId()->equals($content->getId())) {
                return true;
            }
        }
        foreach ($this->catalogGrants->findByVersion($version) as $grant) {
            if (AccessPackageCatalogResourceKind::LearningContent !== $grant->getResourceKind()) {
                continue;
            }
            if ($grant->getGradeLevel() !== $content->getGradeLevel()) {
                continue;
            }
            $subject = $grant->getSubject();
            if (null !== $subject && $subject->getId()->equals($content->getSubject()->getId())) {
                return true;
            }
        }

        return false;
    }

    private function versionCoversAssessment(AccessPackageVersion $version, Assessment $assessment): bool
    {
        foreach ($this->assessmentGrants->findByVersion($version) as $grant) {
            if ($grant->getAssessment()->getId()->equals($assessment->getId())) {
                return true;
            }
        }
        foreach ($this->catalogGrants->findByVersion($version) as $grant) {
            if (AccessPackageCatalogResourceKind::Assessment !== $grant->getResourceKind()) {
                continue;
            }
            if ($grant->getGradeLevel() === $assessment->getGradeLevel()) {
                return true;
            }
        }

        return false;
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

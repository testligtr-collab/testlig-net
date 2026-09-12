<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\LearningContent;
use App\Entity\LearningContentRevision;
use App\Entity\LearningContentRevisionAsset;
use App\Entity\User;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\LearningContentAccessDecisionReason;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\UserStatus;
use App\LearningContent\LearningContentAccessDecision;
use App\Repository\LearningContentRevisionAssetRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

/**
 * Fail-closed student/delivery access gate for learning content.
 *
 * Published platform content returns entitlement_required (no free student access).
 * Entitlement wiring is intentionally pending in Stage 2.15.
 *
 * Fresh loads use HINT_REFRESH without pessimistic locks (read path; no open TX required).
 */
final class LearningContentAccessGate
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LearningContentRevisionAssetRepository $revisionAssets,
    ) {
    }

    public function evaluate(Uuid $contentId, User $actor): LearningContentAccessDecision
    {
        try {
            return $this->doEvaluate($contentId, $actor);
        } catch (DeadlockException|LockWaitTimeoutException) {
            return LearningContentAccessDecision::denied(LearningContentAccessDecisionReason::Unauthorized);
        }
    }

    private function doEvaluate(Uuid $contentId, User $actor): LearningContentAccessDecision
    {
        $content = $this->findFreshContent($contentId);
        if (!$content instanceof LearningContent) {
            return LearningContentAccessDecision::denied(LearningContentAccessDecisionReason::NotFound);
        }

        $contentIdStr = $content->getId()->toRfc4122();

        if (LearningContentStatus::Archived === $content->getStatus()) {
            return LearningContentAccessDecision::denied(
                LearningContentAccessDecisionReason::Archived,
                $contentIdStr,
            );
        }

        if (LearningContentStatus::Published !== $content->getStatus()) {
            return LearningContentAccessDecision::denied(
                LearningContentAccessDecisionReason::NotPublished,
                $contentIdStr,
            );
        }

        $publishedRevision = $content->getPublishedRevision();
        if (!$publishedRevision instanceof LearningContentRevision
            || null === $content->getPublishedRevisionNumber()
        ) {
            return LearningContentAccessDecision::denied(
                LearningContentAccessDecisionReason::AccessPolicyNotConfigured,
                $contentIdStr,
            );
        }

        $revision = $this->findFreshRevision($publishedRevision->getId());
        if (!$revision instanceof LearningContentRevision || !$revision->isSealed()) {
            return LearningContentAccessDecision::denied(
                LearningContentAccessDecisionReason::AccessPolicyNotConfigured,
                $contentIdStr,
            );
        }

        $revisionIdStr = $revision->getId()->toRfc4122();
        $revisionNumber = $revision->getRevisionNumber();

        $freshUser = $this->findFreshUser($actor->getId());
        if (!$freshUser instanceof User || UserStatus::Active !== $freshUser->getStatus()) {
            return LearningContentAccessDecision::denied(
                LearningContentAccessDecisionReason::Unauthorized,
                $contentIdStr,
                $revisionIdStr,
                $revisionNumber,
            );
        }
        if (null === $freshUser->getEmailVerifiedAt()) {
            return LearningContentAccessDecision::denied(
                LearningContentAccessDecisionReason::Unauthorized,
                $contentIdStr,
                $revisionIdStr,
                $revisionNumber,
            );
        }

        if (LearningContentScope::Platform === $content->getScope()) {
            return LearningContentAccessDecision::denied(
                LearningContentAccessDecisionReason::EntitlementRequired,
                $contentIdStr,
                $revisionIdStr,
                $revisionNumber,
            );
        }

        $institution = $content->getInstitution();
        if (!$institution instanceof Institution) {
            return LearningContentAccessDecision::denied(
                LearningContentAccessDecisionReason::TenantMismatch,
                $contentIdStr,
                $revisionIdStr,
                $revisionNumber,
            );
        }

        $lockedInstitution = $this->findFreshInstitution($institution->getId());
        if (!$lockedInstitution instanceof Institution
            || InstitutionStatus::Active !== $lockedInstitution->getStatus()
        ) {
            return LearningContentAccessDecision::denied(
                LearningContentAccessDecisionReason::InstitutionInactive,
                $contentIdStr,
                $revisionIdStr,
                $revisionNumber,
            );
        }

        $membership = $this->findActiveMembership($freshUser, $lockedInstitution);
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            return LearningContentAccessDecision::denied(
                LearningContentAccessDecisionReason::MembershipInactive,
                $contentIdStr,
                $revisionIdStr,
                $revisionNumber,
            );
        }

        if (!$this->attachedAssetsReady($revision)) {
            return LearningContentAccessDecision::denied(
                LearningContentAccessDecisionReason::AssetNotReady,
                $contentIdStr,
                $revisionIdStr,
                $revisionNumber,
            );
        }

        return LearningContentAccessDecision::denied(
            LearningContentAccessDecisionReason::EntitlementRequired,
            $contentIdStr,
            $revisionIdStr,
            $revisionNumber,
        );
    }

    private function attachedAssetsReady(LearningContentRevision $revision): bool
    {
        /** @var list<LearningContentRevisionAsset> $links */
        $links = $this->revisionAssets->findByRevision($revision);
        foreach ($links as $link) {
            if (StoredMediaAssetStatus::Ready !== $link->getAsset()->getStatus()) {
                return false;
            }
        }

        return true;
    }

    private function findFreshContent(Uuid $id): ?LearningContent
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(LearningContent::class, 'c')
            ->where('c.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true);
        $entity = $query->getOneOrNullResult();

        return $entity instanceof LearningContent ? $entity : null;
    }

    private function findFreshRevision(Uuid $id): ?LearningContentRevision
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(LearningContentRevision::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true);
        $entity = $query->getOneOrNullResult();

        return $entity instanceof LearningContentRevision ? $entity : null;
    }

    private function findFreshUser(Uuid $id): ?User
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true);
        $entity = $query->getOneOrNullResult();

        return $entity instanceof User ? $entity : null;
    }

    private function findFreshInstitution(Uuid $id): ?Institution
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Institution::class, 'i')
            ->where('i.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true);
        $entity = $query->getOneOrNullResult();

        return $entity instanceof Institution ? $entity : null;
    }

    private function findActiveMembership(User $user, Institution $institution): ?InstitutionMembership
    {
        $membership = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(InstitutionMembership::class, 'm')
            ->andWhere('m.user = :user')
            ->andWhere('m.institution = :institution')
            ->andWhere('m.status = :status')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('status', InstitutionMembershipStatus::Active)
            ->setMaxResults(1)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $membership instanceof InstitutionMembership ? $membership : null;
    }
}

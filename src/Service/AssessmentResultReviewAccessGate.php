<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\StudentResultReviewDecision;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentResultReviewPolicy;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\AssessmentResultReviewFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\ResultReviewAvailabilityMode;
use App\Exception\AssessmentResultReviewException;
use App\Repository\AssessmentResultReviewPolicyRepository;
use App\ResultReview\AssessmentResultReviewPolicyHasher;
use App\Time\UtcInstant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Fresh authorization for review-policy management and student review decisions.
 *
 * Never trusts caller-provided User status/roles/verification from the identity map.
 */
final class AssessmentResultReviewAccessGate
{
    public function __construct(
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly AssessmentResultReviewPolicyRepository $policies,
        private readonly AssessmentResultReviewPolicyHasher $hasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function assertCanManagePolicy(User $actor, AssessmentDelivery $delivery): void
    {
        $freshActor = $this->requireFreshActiveVerifiedActor($actor->getId());
        $freshDelivery = $this->requireFreshDelivery($delivery->getId());

        if ($this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($freshActor)) {
            return;
        }

        $institution = $this->requireActiveInstitution($freshDelivery->getInstitution()->getId());
        $membership = $this->requireActiveMembership($freshActor->getId(), $institution->getId());

        if (!\in_array($membership->getRole(), [
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
        ], true)) {
            throw AssessmentResultReviewException::unauthorized();
        }
    }

    public function decideStudentReview(User $actor, AssessmentAttempt $attempt): StudentResultReviewDecision
    {
        $freshActor = $this->requireFreshActiveVerifiedActor($actor->getId());
        $freshAttempt = $this->requireFreshAttempt($attempt->getId());
        $this->assertCanViewReview($freshActor, $freshAttempt);

        $delivery = $this->requireFreshDelivery($freshAttempt->getDelivery()->getId());
        $policy = $this->policies->findActiveForDelivery($delivery->getId());
        if (!$policy instanceof AssessmentResultReviewPolicy) {
            return StudentResultReviewDecision::denied(
                AssessmentResultReviewFailureReason::ReviewPolicyNotActive,
            );
        }

        try {
            $this->hasher->verify(
                $policy->getPolicyHash(),
                $policy->getSchemaVersion(),
                $policy->getAvailabilityMode(),
                $policy->getScheduledAt(),
                $policy->showScoreSummary(),
                $policy->showItemOutcomes(),
                $policy->showStudentAnswer(),
                $policy->showCorrectAnswer(),
                $policy->showExplanation(),
            );
        } catch (AssessmentResultReviewException) {
            return StudentResultReviewDecision::denied(
                AssessmentResultReviewFailureReason::PolicyIntegrityFailed,
            );
        }

        $now = UtcInstant::ensure($this->clock->now());
        $closesAt = UtcInstant::ensure($delivery->getClosesAt());
        $cancelled = AssessmentDeliveryStatus::Cancelled === $delivery->getStatus();
        $mode = $policy->getAvailabilityMode();

        $sensitiveRevealAt = match ($mode) {
            ResultReviewAvailabilityMode::Never => null,
            ResultReviewAvailabilityMode::AfterDeliveryClosed => $closesAt,
            ResultReviewAvailabilityMode::ScheduledAfterClose => $this->maxInstant(
                $closesAt,
                UtcInstant::ensure($policy->getScheduledAt() ?? $closesAt),
            ),
        };

        $sensitiveUnlocked = null !== $sensitiveRevealAt
            && $now >= $sensitiveRevealAt
            && !$cancelled;

        $allowScoreSummary = $policy->showScoreSummary();
        $allowItemOutcomes = ResultReviewAvailabilityMode::Never !== $mode
            && $policy->showItemOutcomes();
        $allowStudentAnswer = $allowItemOutcomes && $policy->showStudentAnswer();
        $allowCorrectAnswer = $allowItemOutcomes
            && $policy->showCorrectAnswer()
            && $sensitiveUnlocked;
        $allowExplanation = $allowItemOutcomes
            && $policy->showExplanation()
            && $sensitiveUnlocked;

        if (!$allowScoreSummary && !$allowItemOutcomes) {
            return StudentResultReviewDecision::denied(
                AssessmentResultReviewFailureReason::ReviewNotAvailable,
            );
        }

        return new StudentResultReviewDecision(
            true,
            null,
            $policy,
            $sensitiveRevealAt,
            $allowScoreSummary,
            $allowItemOutcomes,
            $allowStudentAnswer,
            $allowCorrectAnswer,
            $allowExplanation,
            $cancelled,
        );
    }

    private function assertCanViewReview(User $freshActor, AssessmentAttempt $freshAttempt): void
    {
        if ($this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($freshActor)) {
            return;
        }

        $institution = $this->requireActiveInstitution($freshAttempt->getInstitution()->getId());
        $membership = $this->requireActiveMembership($freshActor->getId(), $institution->getId());

        // Stage 2.13: StudentResultReviewView is student-owned only (no teacher/staff impersonation).
        // Owner/Manager manage policies separately; teacher analysis DTO is deferred.
        if ($freshActor->getId()->equals($freshAttempt->getUser()->getId())
            && InstitutionMembershipRole::Student === $membership->getRole()
        ) {
            return;
        }

        throw AssessmentResultReviewException::unauthorized();
    }

    private function maxInstant(\DateTimeImmutable $a, \DateTimeImmutable $b): \DateTimeImmutable
    {
        return $a >= $b ? $a : $b;
    }

    private function requireFreshActiveVerifiedActor(Uuid $actorId): User
    {
        $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
        $freshActor = $users[$actorId->toRfc4122()] ?? null;
        if (!$freshActor instanceof User
            || !$this->activeVerifiedUserPolicy->isActiveAndVerified($freshActor)
        ) {
            throw AssessmentResultReviewException::unauthorized();
        }

        return $freshActor;
    }

    private function requireFreshAttempt(Uuid $attemptId): AssessmentAttempt
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(AssessmentAttempt::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $attemptId, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode(LockMode::PESSIMISTIC_READ);
        $attempt = $query->getOneOrNullResult();
        if (!$attempt instanceof AssessmentAttempt) {
            throw AssessmentResultReviewException::notFound();
        }

        return $attempt;
    }

    private function requireFreshDelivery(Uuid $deliveryId): AssessmentDelivery
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(AssessmentDelivery::class, 'd')
            ->where('d.id = :id')
            ->setParameter('id', $deliveryId, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode(LockMode::PESSIMISTIC_READ);
        $delivery = $query->getOneOrNullResult();
        if (!$delivery instanceof AssessmentDelivery) {
            throw AssessmentResultReviewException::notFound();
        }

        return $delivery;
    }

    private function requireActiveInstitution(Uuid $institutionId): Institution
    {
        $institution = $this->freshEntities->findFreshLockedInstitution(
            $institutionId,
            LockMode::PESSIMISTIC_READ,
        );
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw AssessmentResultReviewException::unauthorized();
        }

        return $institution;
    }

    private function requireActiveMembership(Uuid $userId, Uuid $institutionId): InstitutionMembership
    {
        $membership = $this->freshEntities->findFreshMembershipForUser(
            $userId,
            $institutionId,
            LockMode::PESSIMISTIC_READ,
        );
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw AssessmentResultReviewException::unauthorized();
        }

        return $membership;
    }
}

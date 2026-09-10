<?php

declare(strict_types=1);

namespace App\Service;

use App\Assessment\AssessmentDeliveryAccessDecision;
use App\Assessment\AssessmentPublicationIntegrityVerifier;
use App\Entity\Assessment;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentRevision;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AssessmentDeliveryAccessReason;
use App\Enum\AssessmentDeliveryRecipientStatus;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\UserStatus;
use App\Exception\AssessmentDeliveryException;
use App\Exception\AssessmentException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Fresh-state student access gate for assessment delivery (no attempt creation yet).
 *
 * Evaluation order:
 * 1. Delivery fresh load
 * 2. Recipient scoped by delivery + user
 * 3. User active+verified
 * 4. Institution active
 * 5. Membership active+student
 * 6. Recipient eligible
 * 7. Delivery active
 * 8. opensAt/closesAt vs ClockInterface
 * 9. AssessmentPublicationIntegrityVerifier (never loads answer keys)
 * 10. Mark attemptQuotaMustBeChecked when allowed
 */
final class AssessmentDeliveryAccessGate
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssessmentPublicationIntegrityVerifier $publicationIntegrityVerifier,
        private readonly ClockInterface $clock,
    ) {
    }

    public function evaluate(Uuid $deliveryId, User $student): AssessmentDeliveryAccessDecision
    {
        try {
            return $this->doEvaluate($deliveryId, $student);
        } catch (DeadlockException|LockWaitTimeoutException) {
            return AssessmentDeliveryAccessDecision::denied(AssessmentDeliveryAccessReason::Conflict);
        } catch (AssessmentDeliveryException $e) {
            return AssessmentDeliveryAccessDecision::denied(
                $this->mapFailureToAccessReason($e),
                $deliveryId->toRfc4122(),
            );
        }
    }

    private function doEvaluate(Uuid $deliveryId, User $student): AssessmentDeliveryAccessDecision
    {
        $delivery = $this->findFreshDelivery($deliveryId);
        if (!$delivery instanceof AssessmentDelivery) {
            return AssessmentDeliveryAccessDecision::denied(AssessmentDeliveryAccessReason::DeliveryNotFound);
        }

        $deliveryIdStr = $delivery->getId()->toRfc4122();
        $publicationIdStr = $delivery->getAssessmentPublication()->getId()->toRfc4122();
        $publicationNumber = $delivery->getPublicationNumber();
        $maxAttempts = $delivery->getMaxAttempts();
        $opensAt = $delivery->getOpensAt();
        $closesAt = $delivery->getClosesAt();

        $recipient = $this->findFreshRecipientForUser($delivery->getId(), $student->getId());
        if (!$recipient instanceof AssessmentDeliveryRecipient) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::RecipientNotFound,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }

        if (!$recipient->getUser()->getId()->equals($student->getId())) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::RecipientNotFound,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }

        if (!$recipient->getInstitution()->getId()->equals($delivery->getInstitution()->getId())) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::Conflict,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }

        if (!$delivery->getAssessment()->getId()->equals($delivery->getAssessmentPublication()->getAssessment()->getId())
            || $delivery->getPublicationNumber() !== $delivery->getAssessmentPublication()->getPublicationNumber()
        ) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::PublicationIntegrityFailed,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }

        $freshUser = $this->findFreshUser($student->getId());
        if (!$freshUser instanceof User || UserStatus::Active !== $freshUser->getStatus()) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::UserInactive,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }
        if (null === $freshUser->getEmailVerifiedAt()) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::EmailNotVerified,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }

        $institution = $this->findFreshInstitution($delivery->getInstitution()->getId());
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::InstitutionInactive,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }

        $membership = $this->findFreshMembership($recipient->getStudentMembership()->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::MembershipInactive,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }
        if (InstitutionMembershipRole::Student !== $membership->getRole()) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::MembershipNotStudent,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }
        if (!$membership->getUser()->getId()->equals($freshUser->getId())
            || !$membership->getInstitution()->getId()->equals($delivery->getInstitution()->getId())
        ) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::Conflict,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }

        if (AssessmentDeliveryRecipientStatus::Revoked === $recipient->getStatus()) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::RecipientRevoked,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }

        if (AssessmentDeliveryStatus::Active !== $delivery->getStatus()) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::DeliveryNotActive,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        if ($now < $opensAt) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::NotOpenYet,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }
        if ($now >= $closesAt) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::Expired,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }

        $publication = $this->findFreshPublication($delivery->getAssessmentPublication()->getId());
        $assessment = $this->findFreshAssessment($delivery->getAssessment()->getId());
        if (!$publication instanceof AssessmentPublication || !$assessment instanceof Assessment) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::PublicationIntegrityFailed,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }
        $revision = $this->findFreshRevision($publication->getAssessmentRevision()->getId());
        if (!$revision instanceof AssessmentRevision) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::PublicationIntegrityFailed,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }

        try {
            $this->publicationIntegrityVerifier->verify($publication, $assessment, $revision);
        } catch (AssessmentException) {
            return AssessmentDeliveryAccessDecision::denied(
                AssessmentDeliveryAccessReason::PublicationIntegrityFailed,
                $deliveryIdStr,
                $publicationIdStr,
                $publicationNumber,
                $maxAttempts,
                $opensAt,
                $closesAt,
            );
        }

        // Never load answer keys / HMAC in this gate (Stage 2.11 owns attempts).

        return AssessmentDeliveryAccessDecision::allowed(
            $deliveryIdStr,
            $publicationIdStr,
            $publicationNumber,
            $maxAttempts,
            $opensAt,
            $closesAt,
        );
    }

    private function findFreshDelivery(Uuid $id): ?AssessmentDelivery
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(AssessmentDelivery::class, 'd')
            ->where('d.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentDelivery ? $result : null;
    }

    private function findFreshRecipientForUser(Uuid $deliveryId, Uuid $userId): ?AssessmentDeliveryRecipient
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AssessmentDeliveryRecipient::class, 'r')
            ->where('r.delivery = :deliveryId')
            ->andWhere('r.user = :userId')
            ->setParameter('deliveryId', $deliveryId, 'uuid')
            ->setParameter('userId', $userId, 'uuid')
            ->setMaxResults(1);
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentDeliveryRecipient ? $result : null;
    }

    private function findFreshUser(Uuid $id): ?User
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof User ? $result : null;
    }

    private function findFreshInstitution(Uuid $id): ?Institution
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Institution::class, 'i')
            ->where('i.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof Institution ? $result : null;
    }

    private function findFreshMembership(Uuid $id): ?InstitutionMembership
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(InstitutionMembership::class, 'm')
            ->where('m.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof InstitutionMembership ? $result : null;
    }

    private function findFreshPublication(Uuid $id): ?AssessmentPublication
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(AssessmentPublication::class, 'p')
            ->where('p.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentPublication ? $result : null;
    }

    private function findFreshAssessment(Uuid $id): ?Assessment
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof Assessment ? $result : null;
    }

    private function findFreshRevision(Uuid $id): ?AssessmentRevision
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AssessmentRevision::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentRevision ? $result : null;
    }

    private function mapFailureToAccessReason(AssessmentDeliveryException $e): AssessmentDeliveryAccessReason
    {
        return match ($e->getReason()) {
            default => AssessmentDeliveryAccessReason::Conflict,
        };
    }
}

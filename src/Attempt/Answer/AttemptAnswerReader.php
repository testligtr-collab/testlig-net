<?php

declare(strict_types=1);

namespace App\Attempt\Answer;

use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentAttemptAnswer;
use App\Entity\AssessmentAttemptItem;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AssessmentDeliveryRecipientStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\UserStatus;
use App\Exception\AssessmentAttemptException;
use App\Service\InstitutionalFreshEntityLoader;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

/**
 * Decrypts attempt answers for authorized internal/test use only (attempt owner).
 * Do not expose from HTTP controllers without an explicit authorization layer.
 *
 * Authorization always reloads from the database before decrypt (HINT_REFRESH).
 * Owner identity only: teacher/owner/manager/staff/ADMIN/MODERATOR/SUPER_ADMIN
 * who are not the attempt student are denied.
 */
final class AttemptAnswerReader
{
    public function __construct(
        private readonly AttemptAnswerEncryptor $encryptor,
        private readonly EntityManagerInterface $entityManager,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function readForOwner(AssessmentAttemptAnswer $answer, User $student): array
    {
        $answerId = $answer->getId();
        $callerId = $student->getId();

        $freshAnswer = $this->findFreshAnswer($answerId);
        if (!$freshAnswer instanceof AssessmentAttemptAnswer) {
            throw AssessmentAttemptException::notFound();
        }

        $attemptId = $freshAnswer->getAttempt()->getId();
        $attemptItemId = $freshAnswer->getAttemptItem()->getId();

        $freshAttempt = $this->findFreshAttempt($attemptId);
        if (!$freshAttempt instanceof AssessmentAttempt) {
            throw AssessmentAttemptException::notFound();
        }

        $freshItem = $this->findFreshAttemptItem($attemptItemId);
        if (!$freshItem instanceof AssessmentAttemptItem) {
            throw AssessmentAttemptException::itemNotFound();
        }
        if (!$freshItem->getAttempt()->getId()->equals($freshAttempt->getId())
            || !$freshItem->getAttempt()->getId()->equals($attemptId)
        ) {
            throw AssessmentAttemptException::unauthorized();
        }
        if (!$freshAnswer->getAttempt()->getId()->equals($freshAttempt->getId())) {
            throw AssessmentAttemptException::unauthorized();
        }

        $users = $this->freshEntities->findFreshLockedUsers([$callerId], LockMode::NONE);
        $freshCaller = $users[$callerId->toRfc4122()] ?? null;
        if (!$freshCaller instanceof User || UserStatus::Active !== $freshCaller->getStatus()) {
            throw AssessmentAttemptException::userInactive();
        }
        if (null === $freshCaller->getEmailVerifiedAt()) {
            throw AssessmentAttemptException::emailNotVerified();
        }

        $institution = $this->freshEntities->findFreshLockedInstitution(
            $freshAttempt->getInstitution()->getId(),
            LockMode::NONE,
        );
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw AssessmentAttemptException::institutionInactive();
        }

        $membership = $this->freshEntities->findFreshLockedMembership(
            $freshAttempt->getStudentMembership()->getId(),
            LockMode::NONE,
        );
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw AssessmentAttemptException::membershipInactive();
        }
        if (InstitutionMembershipRole::Student !== $membership->getRole()) {
            throw AssessmentAttemptException::membershipNotStudent();
        }
        if (!$membership->getUser()->getId()->equals($freshAttempt->getUser()->getId())
            || !$membership->getInstitution()->getId()->equals($freshAttempt->getInstitution()->getId())
        ) {
            throw AssessmentAttemptException::scopeMismatch();
        }

        $recipient = $this->findFreshRecipient($freshAttempt->getRecipient()->getId());
        if (!$recipient instanceof AssessmentDeliveryRecipient) {
            throw AssessmentAttemptException::recipientNotFound();
        }
        if (AssessmentDeliveryRecipientStatus::Revoked === $recipient->getStatus()) {
            throw AssessmentAttemptException::recipientRevoked();
        }
        if (!$recipient->getUser()->getId()->equals($freshAttempt->getUser()->getId())
            || !$recipient->getStudentMembership()->getId()->equals($freshAttempt->getStudentMembership()->getId())
            || !$recipient->getInstitution()->getId()->equals($freshAttempt->getInstitution()->getId())
            || !$recipient->getDelivery()->getId()->equals($freshAttempt->getDelivery()->getId())
        ) {
            throw AssessmentAttemptException::scopeMismatch();
        }

        // Owner-only: privileged roles who are not the attempt student are denied.
        if (!$freshCaller->getId()->equals($freshAttempt->getUser()->getId())) {
            throw AssessmentAttemptException::unauthorized();
        }

        return $this->encryptor->decrypt(
            $freshAnswer->getAnswerCiphertext(),
            $freshAnswer->getAnswerNonce(),
            $freshAnswer->getEncryptionVersion(),
            $freshAttempt->getId()->toRfc4122(),
            $freshItem->getId()->toRfc4122(),
            $freshCaller->getId()->toRfc4122(),
        );
    }

    private function findFreshAnswer(Uuid $id): ?AssessmentAttemptAnswer
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(AssessmentAttemptAnswer::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentAttemptAnswer ? $result : null;
    }

    private function findFreshAttempt(Uuid $id): ?AssessmentAttempt
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(AssessmentAttempt::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentAttempt ? $result : null;
    }

    private function findFreshAttemptItem(Uuid $id): ?AssessmentAttemptItem
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(AssessmentAttemptItem::class, 'i')
            ->where('i.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentAttemptItem ? $result : null;
    }

    private function findFreshRecipient(Uuid $id): ?AssessmentDeliveryRecipient
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AssessmentDeliveryRecipient::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentDeliveryRecipient ? $result : null;
    }
}

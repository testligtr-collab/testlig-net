<?php

declare(strict_types=1);

namespace App\Service;

use App\Assessment\AssessmentDeliveryContentPolicy;
use App\Assessment\AssessmentPublicationIntegrityVerifier;
use App\Dto\SecurityAuditContext;
use App\Entity\Assessment;
use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentRevision;
use App\Entity\Classroom;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AcademicYearStatus;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentDeliveryRecipientStatus;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\ClassroomCourseStatus;
use App\Enum\ClassroomStatus;
use App\Enum\CourseTeacherAssignmentStatus;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\StudentEnrollmentStatus;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserStatus;
use App\Exception\AssessmentDeliveryException;
use App\Exception\AssessmentException;
use App\Repository\AssessmentDeliveryRecipientRepository;
use App\Repository\AssessmentDeliveryRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Assessment delivery lifecycle, recipient materialization, and recipient management.
 *
 * Global lock order:
 * 1. Locksless delivery / institution / publication scope snapshots
 * 2. Institution PESSIMISTIC_WRITE + HINT_REFRESH
 * 3. AcademicYear / Classroom (when classroom audience) WRITE
 * 4. Assessment READ/WRITE as needed; AssessmentPublication READ
 * 5. Users UUID ascending
 * 6. Memberships
 * 7. Enrollment / teacher-assignment reads
 * 8. Delivery PESSIMISTIC_WRITE + HINT_REFRESH; revalidate
 * 9. Recipient inserts / updates
 * 10. Audit in same transaction; invalidate auth cache only after commit
 *
 * Known limitation: no multi-process concurrency harness; uniqueness + pessimistic locks
 * provide sequential safety only.
 */
final class AssessmentDeliveryManager
{
    public function __construct(
        private readonly AssessmentDeliveryRepository $deliveries,
        private readonly AssessmentDeliveryRecipientRepository $recipients,
        private readonly AssessmentDeliveryContentPolicy $contentPolicy,
        private readonly AssessmentPublicationIntegrityVerifier $publicationIntegrityVerifier,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createDraft(
        Institution $institution,
        AssessmentPublication $publication,
        AssessmentDeliveryAudienceType $audienceType,
        ?Classroom $classroom,
        ?InstitutionMembership $studentMembership,
        User $actor,
        \DateTimeImmutable $opensAt,
        \DateTimeImmutable $closesAt,
        int $maxAttempts,
        ?string $titleOverride,
        ?string $instructionsOverride,
        string $reasonCode,
    ): AssessmentDelivery {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $titleOverride = $this->contentPolicy->normalizeOptionalTitleOverride($titleOverride);
        $instructionsOverride = $this->contentPolicy->normalizeOptionalInstructionsOverride($instructionsOverride);
        $this->assertWindow($opensAt, $closesAt);
        $this->assertMaxAttempts($maxAttempts);

        $institutionId = $institution->getId();
        $publicationId = $publication->getId();
        $actorId = $actor->getId();
        $classroomId = $classroom?->getId();
        $studentMembershipId = $studentMembership?->getId();

        try {
            $delivery = $this->entityManager->wrapInTransaction(function () use (
                $institutionId,
                $publicationId,
                $audienceType,
                $classroomId,
                $studentMembershipId,
                $actorId,
                $opensAt,
                $closesAt,
                $maxAttempts,
                $titleOverride,
                $instructionsOverride,
                $reasonCode,
            ): AssessmentDelivery {
                $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                    $institutionId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedInstitution instanceof Institution
                    || InstitutionStatus::Active !== $lockedInstitution->getStatus()
                ) {
                    throw AssessmentDeliveryException::institutionInactive();
                }

                $publication = $this->findFreshPublication($publicationId);
                if (!$publication instanceof AssessmentPublication) {
                    throw AssessmentDeliveryException::publicationInvalid();
                }
                $assessment = $this->findFreshAssessment($publication->getAssessment()->getId());
                if (!$assessment instanceof Assessment) {
                    throw AssessmentDeliveryException::notFound();
                }
                $this->assertPublicationUsableForNewDelivery($assessment, $publication, $lockedInstitution);

                $classroom = null;
                if (null !== $classroomId) {
                    $classroom = $this->freshEntities->findFreshLockedClassroom(
                        $classroomId,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$classroom instanceof Classroom) {
                        throw AssessmentDeliveryException::invalidInput('Classroom was not found.');
                    }
                }

                $studentMembership = null;
                if (null !== $studentMembershipId) {
                    $studentMembership = $this->freshEntities->findFreshLockedMembership(
                        $studentMembershipId,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$studentMembership instanceof InstitutionMembership) {
                        throw AssessmentDeliveryException::invalidInput('Student membership was not found.');
                    }
                }

                $userIds = [$actorId];
                if (null !== $studentMembership) {
                    $userIds[] = $studentMembership->getUser()->getId();
                }
                $users = $this->freshEntities->findFreshLockedUsers($userIds, LockMode::PESSIMISTIC_WRITE);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AssessmentDeliveryException::unauthorized();
                }

                $this->assertActorMayCreate(
                    $freshActor,
                    $lockedInstitution,
                    $audienceType,
                    $classroom,
                    $studentMembership,
                );

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $delivery = AssessmentDelivery::createDraft(
                    $lockedInstitution,
                    $assessment,
                    $publication,
                    $audienceType,
                    $classroom,
                    $studentMembership,
                    $opensAt,
                    $closesAt,
                    $maxAttempts,
                    $titleOverride,
                    $instructionsOverride,
                    $freshActor,
                    $now,
                );
                $this->deliveries->save($delivery, false);
                $this->entityManager->flush();

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentDeliveryCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'assessment_delivery_manager',
                        'reason_code' => $reasonCode,
                        'delivery_id' => $delivery->getId()->toRfc4122(),
                        'institution_id' => $lockedInstitution->getId()->toRfc4122(),
                        'assessment_id' => $assessment->getId()->toRfc4122(),
                        'assessment_publication_id' => $publication->getId()->toRfc4122(),
                        'publication_number' => $publication->getPublicationNumber(),
                        'audience_type' => $audienceType->value,
                        'classroom_id' => $classroom?->getId()->toRfc4122(),
                        'membership_id' => $studentMembership?->getId()->toRfc4122(),
                        'new_status' => AssessmentDeliveryStatus::Draft->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();

                return $delivery;
            });
        } catch (AssessmentDeliveryException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentDeliveryException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateAssessmentDelivery($delivery->getId());

        return $delivery;
    }

    public function updateDraft(
        AssessmentDelivery $delivery,
        User $actor,
        ?\DateTimeImmutable $opensAt,
        ?\DateTimeImmutable $closesAt,
        ?int $maxAttempts,
        ?string $titleOverride,
        ?string $instructionsOverride,
        string $reasonCode,
    ): void {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $deliveryId = $delivery->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $deliveryId,
                $actorId,
                $opensAt,
                $closesAt,
                $maxAttempts,
                $titleOverride,
                $instructionsOverride,
                $reasonCode,
            ): void {
                [$lockedDelivery, $freshActor] = $this->lockDeliveryAndActor($deliveryId, $actorId);
                $this->assertActorMayManage($freshActor, $lockedDelivery, 'update_draft');

                if (AssessmentDeliveryStatus::Draft !== $lockedDelivery->getStatus()) {
                    throw AssessmentDeliveryException::invalidTransition();
                }

                $nextOpens = $opensAt ?? $lockedDelivery->getOpensAt();
                $nextCloses = $closesAt ?? $lockedDelivery->getClosesAt();
                $nextMax = $maxAttempts ?? $lockedDelivery->getMaxAttempts();
                $nextTitle = null !== $titleOverride
                    ? $this->contentPolicy->normalizeOptionalTitleOverride($titleOverride)
                    : $lockedDelivery->getTitleOverride();
                $nextInstructions = null !== $instructionsOverride
                    ? $this->contentPolicy->normalizeOptionalInstructionsOverride($instructionsOverride)
                    : $lockedDelivery->getInstructionsOverride();

                $this->assertWindow($nextOpens, $nextCloses);
                $this->assertMaxAttempts($nextMax);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $lockedDelivery->updateDraftWindow(
                    $nextOpens,
                    $nextCloses,
                    $nextMax,
                    $nextTitle,
                    $nextInstructions,
                    $now,
                );
                $this->entityManager->flush();

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentDeliveryDraftUpdated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'assessment_delivery_manager',
                        'reason_code' => $reasonCode,
                        'delivery_id' => $lockedDelivery->getId()->toRfc4122(),
                        'institution_id' => $lockedDelivery->getInstitution()->getId()->toRfc4122(),
                        'assessment_id' => $lockedDelivery->getAssessment()->getId()->toRfc4122(),
                        'audience_type' => $lockedDelivery->getAudienceType()->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();
            });
        } catch (AssessmentDeliveryException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentDeliveryException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateAssessmentDelivery($deliveryId);
    }

    public function activate(AssessmentDelivery $delivery, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $deliveryId = $delivery->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use ($deliveryId, $actorId, $reasonCode): void {
                [$lockedDelivery, $freshActor] = $this->lockDeliveryAndActor($deliveryId, $actorId);
                $this->assertActorMayManage($freshActor, $lockedDelivery, 'activate');

                if (AssessmentDeliveryStatus::Draft !== $lockedDelivery->getStatus()) {
                    throw AssessmentDeliveryException::invalidTransition();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                if ($lockedDelivery->getClosesAt() <= $now) {
                    throw AssessmentDeliveryException::invalidInput('closesAt must be in the future at activation.');
                }

                $this->assertPublicationStillValid($lockedDelivery);

                $materialized = $this->materializeRecipients($lockedDelivery, $now);
                if (0 === \count($materialized)) {
                    throw AssessmentDeliveryException::noEligibleRecipients();
                }

                foreach ($materialized as $recipient) {
                    $this->recipients->save($recipient, false);
                }
                $this->entityManager->flush();

                $lockedDelivery->activate($freshActor, $now);
                $this->entityManager->flush();

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentDeliveryActivated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'assessment_delivery_manager',
                        'reason_code' => $reasonCode,
                        'delivery_id' => $lockedDelivery->getId()->toRfc4122(),
                        'institution_id' => $lockedDelivery->getInstitution()->getId()->toRfc4122(),
                        'assessment_id' => $lockedDelivery->getAssessment()->getId()->toRfc4122(),
                        'assessment_publication_id' => $lockedDelivery->getAssessmentPublication()->getId()->toRfc4122(),
                        'audience_type' => $lockedDelivery->getAudienceType()->value,
                        'old_status' => AssessmentDeliveryStatus::Draft->value,
                        'new_status' => AssessmentDeliveryStatus::Active->value,
                        'recipient_count' => \count($materialized),
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();
            });
        } catch (AssessmentDeliveryException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentDeliveryException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateAssessmentDelivery($deliveryId);
    }

    public function close(AssessmentDelivery $delivery, User $actor, string $reasonCode): void
    {
        $this->transitionStatus(
            $delivery,
            $actor,
            $reasonCode,
            SecurityAuditAction::AssessmentDeliveryClosed,
            static function (AssessmentDelivery $d, User $a, \DateTimeImmutable $now): void {
                $d->close($a, $now);
            },
            AssessmentDeliveryStatus::Closed,
        );
    }

    public function cancel(
        AssessmentDelivery $delivery,
        User $actor,
        string $reasonCode,
        string $cancellationReasonCode,
    ): void {
        $cancellationReasonCode = $this->contentPolicy->normalizeCancellationReasonCode($cancellationReasonCode);
        $this->transitionStatus(
            $delivery,
            $actor,
            $reasonCode,
            SecurityAuditAction::AssessmentDeliveryCancelled,
            static function (AssessmentDelivery $d, User $a, \DateTimeImmutable $now) use ($cancellationReasonCode): void {
                $d->cancel($a, $cancellationReasonCode, $now);
            },
            AssessmentDeliveryStatus::Cancelled,
        );
    }

    public function revokeRecipient(
        AssessmentDelivery $delivery,
        AssessmentDeliveryRecipient $recipient,
        User $actor,
        string $reasonCode,
    ): void {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $revocationReasonCode = $this->contentPolicy->normalizeRevocationReasonCode($reasonCode);
        $deliveryId = $delivery->getId();
        $recipientId = $recipient->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $deliveryId,
                $recipientId,
                $actorId,
                $reasonCode,
                $revocationReasonCode,
            ): void {
                [$lockedDelivery, $freshActor] = $this->lockDeliveryAndActor($deliveryId, $actorId);
                $this->assertActorMayManage($freshActor, $lockedDelivery, 'recipients_manage');

                if (AssessmentDeliveryStatus::Active !== $lockedDelivery->getStatus()) {
                    throw AssessmentDeliveryException::deliveryNotActive();
                }

                $lockedRecipient = $this->findFreshRecipient($recipientId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedRecipient instanceof AssessmentDeliveryRecipient
                    || !$lockedRecipient->getDelivery()->getId()->equals($lockedDelivery->getId())
                ) {
                    throw AssessmentDeliveryException::recipientNotFound();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $lockedRecipient->revoke($freshActor, $revocationReasonCode, $now);
                $this->entityManager->flush();

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentDeliveryRecipientRevoked,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'assessment_delivery_manager',
                        'reason_code' => $reasonCode,
                        'delivery_id' => $lockedDelivery->getId()->toRfc4122(),
                        'institution_id' => $lockedDelivery->getInstitution()->getId()->toRfc4122(),
                        'recipient_id' => $lockedRecipient->getId()->toRfc4122(),
                        'membership_id' => $lockedRecipient->getStudentMembership()->getId()->toRfc4122(),
                        'old_status' => AssessmentDeliveryRecipientStatus::Eligible->value,
                        'new_status' => AssessmentDeliveryRecipientStatus::Revoked->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();
            });
        } catch (AssessmentDeliveryException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentDeliveryException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateAssessmentDelivery($deliveryId);
    }

    public function addEligibleRecipient(
        AssessmentDelivery $delivery,
        InstitutionMembership $membership,
        User $actor,
        string $reasonCode,
    ): AssessmentDeliveryRecipient {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $deliveryId = $delivery->getId();
        $membershipId = $membership->getId();
        $actorId = $actor->getId();

        try {
            $created = $this->entityManager->wrapInTransaction(function () use (
                $deliveryId,
                $membershipId,
                $actorId,
                $reasonCode,
            ): AssessmentDeliveryRecipient {
                [$lockedDelivery, $freshActor] = $this->lockDeliveryAndActor($deliveryId, $actorId);
                $this->assertActorMayManage($freshActor, $lockedDelivery, 'recipients_manage');

                if (AssessmentDeliveryStatus::Active !== $lockedDelivery->getStatus()) {
                    throw AssessmentDeliveryException::deliveryNotActive();
                }

                $lockedMembership = $this->freshEntities->findFreshLockedMembership(
                    $membershipId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedMembership instanceof InstitutionMembership) {
                    throw AssessmentDeliveryException::invalidInput('Student membership was not found.');
                }

                $studentUser = $this->freshEntities->findFreshLockedUser(
                    $lockedMembership->getUser()->getId(),
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$studentUser instanceof User) {
                    throw AssessmentDeliveryException::userInactive();
                }

                $candidate = $this->buildEligibleCandidate(
                    $lockedDelivery,
                    $lockedMembership,
                    $studentUser,
                    \DateTimeImmutable::createFromInterface($this->clock->now()),
                );
                if (null === $candidate) {
                    throw AssessmentDeliveryException::invalidInput('Membership is not eligible for this delivery.');
                }

                $this->recipients->save($candidate, false);
                $this->entityManager->flush();

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentDeliveryRecipientAdded,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'assessment_delivery_manager',
                        'reason_code' => $reasonCode,
                        'delivery_id' => $lockedDelivery->getId()->toRfc4122(),
                        'institution_id' => $lockedDelivery->getInstitution()->getId()->toRfc4122(),
                        'recipient_id' => $candidate->getId()->toRfc4122(),
                        'membership_id' => $lockedMembership->getId()->toRfc4122(),
                        'audience_type' => $lockedDelivery->getAudienceType()->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();

                return $candidate;
            });
        } catch (AssessmentDeliveryException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentDeliveryException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateAssessmentDelivery($deliveryId);

        return $created;
    }

    /**
     * @param callable(AssessmentDelivery, User, \DateTimeImmutable): void $mutator
     */
    private function transitionStatus(
        AssessmentDelivery $delivery,
        User $actor,
        string $reasonCode,
        SecurityAuditAction $action,
        callable $mutator,
        AssessmentDeliveryStatus $newStatus,
    ): void {
        $reasonCode = $this->contentPolicy->normalizeReasonCode($reasonCode);
        $deliveryId = $delivery->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $deliveryId,
                $actorId,
                $reasonCode,
                $action,
                $mutator,
                $newStatus,
            ): void {
                [$lockedDelivery, $freshActor] = $this->lockDeliveryAndActor($deliveryId, $actorId);
                $manageOp = AssessmentDeliveryStatus::Cancelled === $newStatus ? 'cancel' : 'close';
                $this->assertActorMayManage($freshActor, $lockedDelivery, $manageOp);

                $oldStatus = $lockedDelivery->getStatus();
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $mutator($lockedDelivery, $freshActor, $now);
                $this->entityManager->flush();

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $action,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'assessment_delivery_manager',
                        'reason_code' => $reasonCode,
                        'delivery_id' => $lockedDelivery->getId()->toRfc4122(),
                        'institution_id' => $lockedDelivery->getInstitution()->getId()->toRfc4122(),
                        'assessment_id' => $lockedDelivery->getAssessment()->getId()->toRfc4122(),
                        'audience_type' => $lockedDelivery->getAudienceType()->value,
                        'old_status' => $oldStatus->value,
                        'new_status' => $newStatus->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();
            });
        } catch (AssessmentDeliveryException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentDeliveryException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateAssessmentDelivery($deliveryId);
    }

    /**
     * @return array{0: AssessmentDelivery, 1: User}
     */
    private function lockDeliveryAndActor(Uuid $deliveryId, Uuid $actorId): array
    {
        $scope = $this->fetchDeliveryScopeSnapshot($deliveryId);
        if (null === $scope) {
            throw AssessmentDeliveryException::notFound();
        }

        $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
            $scope['institution_id'],
            LockMode::PESSIMISTIC_WRITE,
        );
        if (!$lockedInstitution instanceof Institution) {
            throw AssessmentDeliveryException::institutionInactive();
        }

        $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_WRITE);
        $freshActor = $users[$actorId->toRfc4122()] ?? null;
        if (!$freshActor instanceof User) {
            throw AssessmentDeliveryException::unauthorized();
        }

        $lockedDelivery = $this->findFreshDelivery($deliveryId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedDelivery instanceof AssessmentDelivery) {
            throw AssessmentDeliveryException::notFound();
        }
        $this->assertDeliverySnapshotUnchanged($lockedDelivery, $scope);

        return [$lockedDelivery, $freshActor];
    }

    /**
     * @return list<AssessmentDeliveryRecipient>
     */
    private function materializeRecipients(AssessmentDelivery $delivery, \DateTimeImmutable $now): array
    {
        return match ($delivery->getAudienceType()) {
            AssessmentDeliveryAudienceType::Institution => $this->materializeInstitutionAudience($delivery, $now),
            AssessmentDeliveryAudienceType::Classroom => $this->materializeClassroomAudience($delivery, $now),
            AssessmentDeliveryAudienceType::Student => $this->materializeStudentAudience($delivery, $now),
        };
    }

    /**
     * @return list<AssessmentDeliveryRecipient>
     */
    private function materializeInstitutionAudience(AssessmentDelivery $delivery, \DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.id AS membership_id
             FROM institution_memberships m
             INNER JOIN users u ON u.id = m.user_id
             WHERE m.institution_id = :institutionId
               AND m.role = :role
               AND m.status = :membershipStatus
               AND u.status = :userStatus
               AND u.email_verified_at IS NOT NULL',
            [
                'institutionId' => $delivery->getInstitution()->getId()->toBinary(),
                'role' => InstitutionMembershipRole::Student->value,
                'membershipStatus' => InstitutionMembershipStatus::Active->value,
                'userStatus' => UserStatus::Active->value,
            ],
        );

        $recipients = [];
        foreach ($rows as $row) {
            $membership = $this->freshEntities->findFreshLockedMembership(
                Uuid::fromBinary($row['membership_id']),
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$membership instanceof InstitutionMembership) {
                continue;
            }
            $user = $this->freshEntities->findFreshLockedUser(
                $membership->getUser()->getId(),
                LockMode::PESSIMISTIC_READ,
            );
            if (!$user instanceof User) {
                continue;
            }
            $candidate = $this->buildEligibleCandidate($delivery, $membership, $user, $now);
            if (null !== $candidate) {
                $recipients[] = $candidate;
            }
        }

        return $recipients;
    }

    /**
     * @return list<AssessmentDeliveryRecipient>
     */
    private function materializeClassroomAudience(AssessmentDelivery $delivery, \DateTimeImmutable $now): array
    {
        $classroom = $delivery->getClassroom();
        if (!$classroom instanceof Classroom) {
            throw AssessmentDeliveryException::invalidInput('Classroom audience requires classroom.');
        }

        $freshClassroom = $this->freshEntities->findFreshLockedClassroom(
            $classroom->getId(),
            LockMode::PESSIMISTIC_WRITE,
        );
        if (!$freshClassroom instanceof Classroom
            || ClassroomStatus::Active !== $freshClassroom->getStatus()
            || !$freshClassroom->getInstitution()->getId()->equals($delivery->getInstitution()->getId())
        ) {
            throw AssessmentDeliveryException::invalidInput('Classroom is not active for delivery.');
        }
        $year = $freshClassroom->getAcademicYear();
        if (AcademicYearStatus::Active !== $year->getStatus()) {
            throw AssessmentDeliveryException::invalidInput('Academic year is not active for delivery.');
        }
        if (InstitutionStatus::Active !== $delivery->getInstitution()->getStatus()) {
            throw AssessmentDeliveryException::institutionInactive();
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id AS enrollment_id, e.student_membership_id, e.classroom_id
             FROM classroom_student_enrollments e
             INNER JOIN institution_memberships m ON m.id = e.student_membership_id
             INNER JOIN users u ON u.id = m.user_id
             WHERE e.classroom_id = :classroomId
               AND e.status = :enrollmentStatus
               AND m.role = :role
               AND m.status = :membershipStatus
               AND u.status = :userStatus
               AND u.email_verified_at IS NOT NULL',
            [
                'classroomId' => $freshClassroom->getId()->toBinary(),
                'enrollmentStatus' => StudentEnrollmentStatus::Active->value,
                'role' => InstitutionMembershipRole::Student->value,
                'membershipStatus' => InstitutionMembershipStatus::Active->value,
                'userStatus' => UserStatus::Active->value,
            ],
        );

        $recipients = [];
        foreach ($rows as $row) {
            $membership = $this->freshEntities->findFreshLockedMembership(
                Uuid::fromBinary($row['student_membership_id']),
                LockMode::PESSIMISTIC_WRITE,
            );
            $enrollment = $this->freshEntities->findFreshLockedStudentEnrollment(
                Uuid::fromBinary($row['enrollment_id']),
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$membership instanceof InstitutionMembership
                || !$enrollment instanceof ClassroomStudentEnrollment
            ) {
                continue;
            }
            $user = $this->freshEntities->findFreshLockedUser(
                $membership->getUser()->getId(),
                LockMode::PESSIMISTIC_READ,
            );
            if (!$user instanceof User) {
                continue;
            }
            $candidate = AssessmentDeliveryRecipient::createEligible(
                $delivery,
                $membership,
                $freshClassroom,
                $enrollment,
                $now,
            );
            if ($this->isEligibleUserAndMembership($membership, $user)) {
                $recipients[] = $candidate;
            }
        }

        return $recipients;
    }

    /**
     * @return list<AssessmentDeliveryRecipient>
     */
    private function materializeStudentAudience(AssessmentDelivery $delivery, \DateTimeImmutable $now): array
    {
        $membership = $delivery->getStudentMembership();
        if (!$membership instanceof InstitutionMembership) {
            throw AssessmentDeliveryException::invalidInput('Student audience requires studentMembership.');
        }
        $lockedMembership = $this->freshEntities->findFreshLockedMembership(
            $membership->getId(),
            LockMode::PESSIMISTIC_WRITE,
        );
        if (!$lockedMembership instanceof InstitutionMembership) {
            return [];
        }
        $user = $this->freshEntities->findFreshLockedUser(
            $lockedMembership->getUser()->getId(),
            LockMode::PESSIMISTIC_WRITE,
        );
        if (!$user instanceof User) {
            return [];
        }
        $candidate = $this->buildEligibleCandidate($delivery, $lockedMembership, $user, $now);

        return null === $candidate ? [] : [$candidate];
    }

    private function buildEligibleCandidate(
        AssessmentDelivery $delivery,
        InstitutionMembership $membership,
        User $user,
        \DateTimeImmutable $now,
    ): ?AssessmentDeliveryRecipient {
        if (!$membership->getInstitution()->getId()->equals($delivery->getInstitution()->getId())) {
            return null;
        }
        if (!$this->isEligibleUserAndMembership($membership, $user)) {
            return null;
        }

        return match ($delivery->getAudienceType()) {
            AssessmentDeliveryAudienceType::Institution => AssessmentDeliveryRecipient::createEligible(
                $delivery,
                $membership,
                null,
                null,
                $now,
            ),
            AssessmentDeliveryAudienceType::Classroom => $this->buildClassroomAddCandidate(
                $delivery,
                $membership,
                $now,
            ),
            AssessmentDeliveryAudienceType::Student => $this->buildStudentAudienceCandidate(
                $delivery,
                $membership,
                $now,
            ),
        };
    }

    private function buildClassroomAddCandidate(
        AssessmentDelivery $delivery,
        InstitutionMembership $membership,
        \DateTimeImmutable $now,
    ): ?AssessmentDeliveryRecipient {
        $classroom = $delivery->getClassroom();
        if (!$classroom instanceof Classroom) {
            return null;
        }
        $enrollmentId = $this->connection->fetchOne(
            'SELECT e.id
             FROM classroom_student_enrollments e
             WHERE e.classroom_id = :classroomId
               AND e.student_membership_id = :membershipId
               AND e.status = :status
             LIMIT 1',
            [
                'classroomId' => $classroom->getId()->toBinary(),
                'membershipId' => $membership->getId()->toBinary(),
                'status' => StudentEnrollmentStatus::Active->value,
            ],
        );
        if (!\is_string($enrollmentId)) {
            return null;
        }
        $enrollment = $this->freshEntities->findFreshLockedStudentEnrollment(
            Uuid::fromBinary($enrollmentId),
            LockMode::PESSIMISTIC_WRITE,
        );
        if (!$enrollment instanceof ClassroomStudentEnrollment) {
            return null;
        }

        return AssessmentDeliveryRecipient::createEligible(
            $delivery,
            $membership,
            $classroom,
            $enrollment,
            $now,
        );
    }

    private function buildStudentAudienceCandidate(
        AssessmentDelivery $delivery,
        InstitutionMembership $membership,
        \DateTimeImmutable $now,
    ): ?AssessmentDeliveryRecipient {
        $target = $delivery->getStudentMembership();
        if (!$target instanceof InstitutionMembership
            || !$target->getId()->equals($membership->getId())
        ) {
            return null;
        }

        return AssessmentDeliveryRecipient::createEligible($delivery, $membership, null, null, $now);
    }

    private function isEligibleUserAndMembership(InstitutionMembership $membership, User $user): bool
    {
        return InstitutionMembershipRole::Student === $membership->getRole()
            && InstitutionMembershipStatus::Active === $membership->getStatus()
            && UserStatus::Active === $user->getStatus()
            && null !== $user->getEmailVerifiedAt()
            && $membership->getUser()->getId()->equals($user->getId());
    }

    private function assertPublicationUsableForNewDelivery(
        Assessment $assessment,
        AssessmentPublication $publication,
        Institution $institution,
    ): void {
        if (AssessmentStatus::Archived === $assessment->getStatus()) {
            throw AssessmentDeliveryException::invalidInput('Archived assessment cannot receive new deliveries.');
        }
        if (null === $assessment->getPublishedRevision()) {
            throw AssessmentDeliveryException::publicationInvalid('Assessment has no published revision.');
        }
        if (!$publication->getAssessment()->getId()->equals($assessment->getId())) {
            throw AssessmentDeliveryException::publicationInvalid();
        }

        if (AssessmentScope::Platform === $assessment->getScope()) {
            // Any active institution may deliver a platform publication.
        } elseif (AssessmentScope::Institution === $assessment->getScope()) {
            $assessmentInstitution = $assessment->getInstitution();
            if (!$assessmentInstitution instanceof Institution
                || !$assessmentInstitution->getId()->equals($institution->getId())
            ) {
                throw AssessmentDeliveryException::scopeMismatch();
            }
        } else {
            throw AssessmentDeliveryException::scopeMismatch();
        }

        $revision = $this->findFreshRevision($publication->getAssessmentRevision()->getId());
        if (!$revision instanceof AssessmentRevision) {
            throw AssessmentDeliveryException::publicationInvalid();
        }
        try {
            $this->publicationIntegrityVerifier->verify($publication, $assessment, $revision);
        } catch (AssessmentException) {
            throw AssessmentDeliveryException::publicationIntegrityFailed();
        }
    }

    private function assertPublicationStillValid(AssessmentDelivery $delivery): void
    {
        $publication = $this->findFreshPublication($delivery->getAssessmentPublication()->getId());
        $assessment = $this->findFreshAssessment($delivery->getAssessment()->getId());
        if (!$publication instanceof AssessmentPublication || !$assessment instanceof Assessment) {
            throw AssessmentDeliveryException::publicationIntegrityFailed();
        }
        $revision = $this->findFreshRevision($publication->getAssessmentRevision()->getId());
        if (!$revision instanceof AssessmentRevision) {
            throw AssessmentDeliveryException::publicationIntegrityFailed();
        }
        try {
            $this->publicationIntegrityVerifier->verify($publication, $assessment, $revision);
        } catch (AssessmentException) {
            throw AssessmentDeliveryException::publicationIntegrityFailed();
        }
    }

    private function assertActorMayCreate(
        User $actor,
        Institution $institution,
        AssessmentDeliveryAudienceType $audienceType,
        ?Classroom $classroom,
        ?InstitutionMembership $studentMembership,
    ): void {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw AssessmentDeliveryException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }

        $membership = $this->freshEntities->findFreshMembershipForUser(
            $actor->getId(),
            $institution->getId(),
            LockMode::PESSIMISTIC_READ,
        );
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw AssessmentDeliveryException::unauthorized();
        }

        if (\in_array($membership->getRole(), [
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
        ], true)) {
            return;
        }

        if (InstitutionMembershipRole::Teacher !== $membership->getRole()) {
            throw AssessmentDeliveryException::unauthorized();
        }

        if (AssessmentDeliveryAudienceType::Institution === $audienceType) {
            throw AssessmentDeliveryException::unauthorized();
        }

        if (AssessmentDeliveryAudienceType::Classroom === $audienceType) {
            if (!$classroom instanceof Classroom
                || !$this->teacherAssignedToClassroom($actor->getId(), $classroom->getId())
            ) {
                throw AssessmentDeliveryException::unauthorized();
            }

            return;
        }

        if (!$studentMembership instanceof InstitutionMembership
            || !$this->teacherCoversStudent(
                $actor->getId(),
                $institution->getId(),
                $studentMembership->getUser()->getId(),
            )
        ) {
            throw AssessmentDeliveryException::unauthorized();
        }
    }

    private function assertActorMayManage(User $actor, AssessmentDelivery $delivery, string $operation): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw AssessmentDeliveryException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }

        $institution = $delivery->getInstitution();
        if (InstitutionStatus::Active !== $institution->getStatus()) {
            throw AssessmentDeliveryException::institutionInactive();
        }

        $membership = $this->freshEntities->findFreshMembershipForUser(
            $actor->getId(),
            $institution->getId(),
            LockMode::PESSIMISTIC_READ,
        );
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw AssessmentDeliveryException::unauthorized();
        }

        if (\in_array($membership->getRole(), [
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
        ], true)) {
            return;
        }

        if (InstitutionMembershipRole::Teacher !== $membership->getRole()) {
            throw AssessmentDeliveryException::unauthorized();
        }

        if (AssessmentDeliveryAudienceType::Institution === $delivery->getAudienceType()) {
            throw AssessmentDeliveryException::unauthorized();
        }

        if (AssessmentDeliveryAudienceType::Classroom === $delivery->getAudienceType()) {
            $classroom = $delivery->getClassroom();
            if (!$classroom instanceof Classroom
                || !$this->teacherAssignedToClassroom($actor->getId(), $classroom->getId())
            ) {
                throw AssessmentDeliveryException::unauthorized();
            }

            return;
        }

        $target = $delivery->getStudentMembership();
        if (!$target instanceof InstitutionMembership
            || !$this->teacherCoversStudent(
                $actor->getId(),
                $institution->getId(),
                $target->getUser()->getId(),
            )
        ) {
            throw AssessmentDeliveryException::unauthorized();
        }

        unset($operation);
    }

    private function teacherAssignedToClassroom(Uuid $userId, Uuid $classroomId): bool
    {
        $homeroom = $this->connection->fetchOne(
            'SELECT 1
             FROM classroom_teacher_assignments a
             INNER JOIN institution_memberships m ON m.id = a.teacher_membership_id
             INNER JOIN classrooms c ON c.id = a.classroom_id
             WHERE a.classroom_id = :classroomId
               AND m.user_id = :userId
               AND m.role = :teacherRole
               AND m.status = :membershipStatus
               AND m.institution_id = c.institution_id
               AND c.status = :classroomStatus
               AND a.status = :assignmentStatus
             LIMIT 1',
            [
                'classroomId' => $classroomId->toBinary(),
                'userId' => $userId->toBinary(),
                'teacherRole' => InstitutionMembershipRole::Teacher->value,
                'membershipStatus' => InstitutionMembershipStatus::Active->value,
                'classroomStatus' => ClassroomStatus::Active->value,
                'assignmentStatus' => TeacherAssignmentStatus::Active->value,
            ],
        );
        if (false !== $homeroom) {
            return true;
        }

        $course = $this->connection->fetchOne(
            'SELECT 1
             FROM course_teacher_assignments a
             INNER JOIN institution_memberships m ON m.id = a.teacher_membership_id
             INNER JOIN classroom_courses cc ON cc.id = a.classroom_course_id
             INNER JOIN classrooms c ON c.id = cc.classroom_id
             WHERE cc.classroom_id = :classroomId
               AND m.user_id = :userId
               AND m.role = :teacherRole
               AND m.status = :membershipStatus
               AND m.institution_id = c.institution_id
               AND c.status = :classroomStatus
               AND cc.status = :courseStatus
               AND a.status = :assignmentStatus
             LIMIT 1',
            [
                'classroomId' => $classroomId->toBinary(),
                'userId' => $userId->toBinary(),
                'teacherRole' => InstitutionMembershipRole::Teacher->value,
                'membershipStatus' => InstitutionMembershipStatus::Active->value,
                'classroomStatus' => ClassroomStatus::Active->value,
                'courseStatus' => ClassroomCourseStatus::Active->value,
                'assignmentStatus' => CourseTeacherAssignmentStatus::Active->value,
            ],
        );

        return false !== $course;
    }

    private function teacherCoversStudent(Uuid $teacherUserId, Uuid $institutionId, Uuid $studentUserId): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT 1
             FROM classroom_student_enrollments e
             INNER JOIN institution_memberships sm ON sm.id = e.student_membership_id
             INNER JOIN classrooms c ON c.id = e.classroom_id
             WHERE e.institution_id = :institutionId
               AND e.status = :enrollmentStatus
               AND sm.user_id = :studentUserId
               AND sm.role = :studentRole
               AND sm.status = :membershipStatus
               AND sm.institution_id = :institutionId
               AND c.status = :classroomStatus
               AND c.institution_id = :institutionId
               AND (
                    EXISTS (
                        SELECT 1 FROM classroom_teacher_assignments ta
                        INNER JOIN institution_memberships tm ON tm.id = ta.teacher_membership_id
                        WHERE ta.classroom_id = e.classroom_id
                          AND tm.user_id = :teacherUserId
                          AND tm.role = :teacherRole
                          AND tm.status = :membershipStatus
                          AND tm.institution_id = :institutionId
                          AND ta.status = :teacherStatus
                    )
                    OR EXISTS (
                        SELECT 1 FROM course_teacher_assignments cta
                        INNER JOIN institution_memberships ctm ON ctm.id = cta.teacher_membership_id
                        INNER JOIN classroom_courses cc ON cc.id = cta.classroom_course_id
                        WHERE cc.classroom_id = e.classroom_id
                          AND ctm.user_id = :teacherUserId
                          AND ctm.role = :teacherRole
                          AND ctm.status = :membershipStatus
                          AND ctm.institution_id = :institutionId
                          AND cc.status = :courseStatus
                          AND cta.status = :courseTeacherStatus
                    )
               )
             LIMIT 1',
            [
                'institutionId' => $institutionId->toBinary(),
                'enrollmentStatus' => StudentEnrollmentStatus::Active->value,
                'studentUserId' => $studentUserId->toBinary(),
                'studentRole' => InstitutionMembershipRole::Student->value,
                'membershipStatus' => InstitutionMembershipStatus::Active->value,
                'classroomStatus' => ClassroomStatus::Active->value,
                'teacherUserId' => $teacherUserId->toBinary(),
                'teacherRole' => InstitutionMembershipRole::Teacher->value,
                'teacherStatus' => TeacherAssignmentStatus::Active->value,
                'courseStatus' => ClassroomCourseStatus::Active->value,
                'courseTeacherStatus' => CourseTeacherAssignmentStatus::Active->value,
            ],
        );

        return false !== $row;
    }

    private function assertWindow(\DateTimeImmutable $opensAt, \DateTimeImmutable $closesAt): void
    {
        if ($opensAt >= $closesAt) {
            throw AssessmentDeliveryException::invalidInput('opensAt must be before closesAt.');
        }
    }

    private function assertMaxAttempts(int $maxAttempts): void
    {
        if ($maxAttempts < 1 || $maxAttempts > 10) {
            throw AssessmentDeliveryException::invalidInput('maxAttempts must be between 1 and 10.');
        }
    }

    /**
     * @return array{
     *     institution_id: Uuid,
     *     assessment_id: Uuid,
     *     assessment_publication_id: Uuid,
     *     audience_type: string,
     *     status: string,
     *     classroom_id: ?Uuid,
     *     student_membership_id: ?Uuid
     * }|null
     */
    private function fetchDeliveryScopeSnapshot(Uuid $deliveryId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT institution_id, assessment_id, assessment_publication_id, audience_type, status,
                    classroom_id, student_membership_id
             FROM assessment_deliveries
             WHERE id = :id
             LIMIT 1',
            ['id' => $deliveryId->toBinary()],
        );
        if (false === $row) {
            return null;
        }

        return [
            'institution_id' => Uuid::fromBinary($row['institution_id']),
            'assessment_id' => Uuid::fromBinary($row['assessment_id']),
            'assessment_publication_id' => Uuid::fromBinary($row['assessment_publication_id']),
            'audience_type' => (string) $row['audience_type'],
            'status' => (string) $row['status'],
            'classroom_id' => null !== $row['classroom_id'] ? Uuid::fromBinary($row['classroom_id']) : null,
            'student_membership_id' => null !== $row['student_membership_id']
                ? Uuid::fromBinary($row['student_membership_id'])
                : null,
        ];
    }

    /**
     * @param array{
     *     institution_id: Uuid,
     *     assessment_id: Uuid,
     *     assessment_publication_id: Uuid,
     *     audience_type: string,
     *     status: string,
     *     classroom_id: ?Uuid,
     *     student_membership_id: ?Uuid
     * } $scope
     */
    private function assertDeliverySnapshotUnchanged(AssessmentDelivery $delivery, array $scope): void
    {
        if (!$delivery->getInstitution()->getId()->equals($scope['institution_id'])
            || !$delivery->getAssessment()->getId()->equals($scope['assessment_id'])
            || !$delivery->getAssessmentPublication()->getId()->equals($scope['assessment_publication_id'])
            || $delivery->getAudienceType()->value !== $scope['audience_type']
            || $delivery->getStatus()->value !== $scope['status']
        ) {
            throw AssessmentDeliveryException::conflict();
        }
        $classroomId = $delivery->getClassroom()?->getId();
        $membershipId = $delivery->getStudentMembership()?->getId();
        if ((null === $classroomId) !== (null === $scope['classroom_id'])
            || (null !== $classroomId && null !== $scope['classroom_id'] && !$classroomId->equals($scope['classroom_id']))
            || (null === $membershipId) !== (null === $scope['student_membership_id'])
            || (null !== $membershipId && null !== $scope['student_membership_id']
                && !$membershipId->equals($scope['student_membership_id']))
        ) {
            throw AssessmentDeliveryException::conflict();
        }
    }

    private function findFreshDelivery(Uuid $id, LockMode $lockMode): ?AssessmentDelivery
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(AssessmentDelivery::class, 'd')
            ->where('d.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode($lockMode);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentDelivery ? $result : null;
    }

    private function findFreshRecipient(Uuid $id, LockMode $lockMode): ?AssessmentDeliveryRecipient
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(AssessmentDeliveryRecipient::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid');
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        $query->setLockMode($lockMode);
        $result = $query->getOneOrNullResult();

        return $result instanceof AssessmentDeliveryRecipient ? $result : null;
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

    private function mapDriverException(\Throwable $throwable): never
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof DriverException && '45000' === $current->getSQLState()) {
                throw AssessmentDeliveryException::immutable();
            }
            if ($current instanceof \Doctrine\DBAL\Driver\Exception && '45000' === $current->getSQLState()) {
                throw AssessmentDeliveryException::immutable();
            }
        }

        throw $throwable;
    }
}

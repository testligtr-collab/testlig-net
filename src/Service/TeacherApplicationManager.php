<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\TeacherApplication;
use App\Entity\User;
use App\Enum\OnboardingApplicationStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Exception\OnboardingApplicationException;
use App\Repository\TeacherApplicationRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher onboarding applications. Never assigns ROLE_TEACHER (Stage 2.22.3).
 */
final class TeacherApplicationManager
{
    private const REASON_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly TeacherApplicationRepository $applications,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function submit(User $applicant): TeacherApplication
    {
        $userId = $applicant->getId();

        try {
            return $this->entityManager->wrapInTransaction(function () use ($userId): TeacherApplication {
                $user = $this->users->findOneByIdForUpdate($userId);
                if (!$user instanceof User) {
                    throw OnboardingApplicationException::applicantNotEligible();
                }
                if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($user)) {
                    throw OnboardingApplicationException::applicantNotEligible();
                }
                if ($this->holdsTeacherPrivilege($user)) {
                    throw OnboardingApplicationException::conflict();
                }
                if (null !== $this->applications->findLatestApprovedForUser($user)) {
                    throw OnboardingApplicationException::conflict();
                }

                $open = $this->applications->findOpenForUserForUpdate($user);
                if ($open instanceof TeacherApplication) {
                    throw OnboardingApplicationException::conflict();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $application = TeacherApplication::createPending($user, $now);
                $this->applications->save($application, false);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::TeacherApplicationSubmitted,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $user,
                    subjectUser: $user,
                    metadata: [
                        'application_id' => $application->getId()->toRfc4122(),
                        'application_kind' => 'teacher',
                        'status' => OnboardingApplicationStatus::Pending->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();

                return $application;
            });
        } catch (OnboardingApplicationException $exception) {
            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            $this->logger->notice('Teacher application submit unique pending race.', [
                'user_id' => $userId->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
            throw OnboardingApplicationException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException $exception) {
            $this->logger->notice('Teacher application submit lock contention.', [
                'user_id' => $userId->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
            throw OnboardingApplicationException::conflict();
        }
    }

    public function withdraw(User $applicant, Uuid $applicationId): void
    {
        $userId = $applicant->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use ($userId, $applicationId): void {
                $user = $this->users->findOneByIdForUpdate($userId);
                if (!$user instanceof User) {
                    throw OnboardingApplicationException::applicantNotEligible();
                }

                $application = $this->applications->findOneForUserForUpdate($user, $applicationId);
                if (!$application instanceof TeacherApplication) {
                    throw OnboardingApplicationException::notFound();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $application->markWithdrawn($now);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::TeacherApplicationWithdrawn,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $user,
                    subjectUser: $user,
                    metadata: [
                        'application_id' => $application->getId()->toRfc4122(),
                        'application_kind' => 'teacher',
                        'status' => OnboardingApplicationStatus::Withdrawn->value,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();
            });
        } catch (OnboardingApplicationException $exception) {
            throw $exception;
        } catch (DeadlockException|LockWaitTimeoutException $exception) {
            $this->logger->notice('Teacher application withdraw lock contention.', [
                'user_id' => $userId->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
            throw OnboardingApplicationException::conflict();
        }
    }

    /**
     * Decision-only: marks approved without granting ROLE_TEACHER (later slice).
     */
    public function markApproved(User $actor, Uuid $applicationId, ?string $reasonCode = null): void
    {
        $this->decide($actor, $applicationId, OnboardingApplicationStatus::Approved, $reasonCode);
    }

    public function markRejected(User $actor, Uuid $applicationId, string $reasonCode): void
    {
        $this->decide($actor, $applicationId, OnboardingApplicationStatus::Rejected, $reasonCode);
    }

    private function decide(User $actor, Uuid $applicationId, OnboardingApplicationStatus $target, ?string $reasonCode): void
    {
        if (OnboardingApplicationStatus::Rejected === $target) {
            $reasonCode = $this->assertReasonCode($reasonCode ?? '');
        } elseif (null !== $reasonCode && '' !== $reasonCode) {
            $reasonCode = $this->assertReasonCode($reasonCode);
        } else {
            $reasonCode = null;
        }

        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use ($actorId, $applicationId, $target, $reasonCode): void {
                $actorUser = $this->users->findOneByIdForUpdate($actorId);
                if (!$actorUser instanceof User || !$this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($actorUser)) {
                    throw OnboardingApplicationException::unauthorized();
                }

                $application = $this->applications->findOneById($applicationId);
                if (!$application instanceof TeacherApplication) {
                    throw OnboardingApplicationException::notFound();
                }

                $applicant = $this->users->findOneByIdForUpdate($application->getUser()->getId());
                if (!$applicant instanceof User) {
                    throw OnboardingApplicationException::notFound();
                }

                $locked = $this->applications->findOneForUserForUpdate($applicant, $applicationId);
                if (!$locked instanceof TeacherApplication) {
                    throw OnboardingApplicationException::notFound();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                if (OnboardingApplicationStatus::Approved === $target) {
                    $locked->markApproved($now, $reasonCode);
                    $action = SecurityAuditAction::TeacherApplicationApproved;
                } else {
                    $locked->markRejected($now, (string) $reasonCode);
                    $action = SecurityAuditAction::TeacherApplicationRejected;
                }

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $action,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $actorUser,
                    subjectUser: $applicant,
                    metadata: [
                        'application_id' => $locked->getId()->toRfc4122(),
                        'application_kind' => 'teacher',
                        'status' => $target->value,
                        'reason_code' => $reasonCode ?? 'none',
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();
            });
        } catch (OnboardingApplicationException $exception) {
            throw $exception;
        } catch (DeadlockException|LockWaitTimeoutException $exception) {
            $this->logger->notice('Teacher application decision lock contention.', [
                'application_id' => $applicationId->toRfc4122(),
                'exception_class' => $exception::class,
            ]);
            throw OnboardingApplicationException::conflict();
        }
    }

    private function holdsTeacherPrivilege(User $user): bool
    {
        $roles = $user->getRoles();

        return \in_array(UserRole::Teacher->value, $roles, true)
            || \in_array(UserRole::ExpertTeacher->value, $roles, true)
            || \in_array(UserRole::HeadTeacher->value, $roles, true);
    }

    private function assertReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match(self::REASON_PATTERN, $reasonCode)) {
            throw OnboardingApplicationException::invalidInput();
        }

        return $reasonCode;
    }
}

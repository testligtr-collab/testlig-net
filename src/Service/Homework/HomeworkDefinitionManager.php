<?php

declare(strict_types=1);

namespace App\Service\Homework;

use App\Dto\HomeworkDraftInput;
use App\Dto\SecurityAuditContext;
use App\Entity\HomeworkDefinition;
use App\Entity\User;
use App\Enum\HomeworkDefinitionStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\HomeworkDefinitionException;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\FreshUserLoader;
use App\Service\SecurityAuditRecorder;
use App\Time\UtcInstant;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher draft homework writes (X01 first slice).
 *
 * Every write runs in one transaction with a fresh actor, current active teacher membership and a
 * current active classroom teacher assignment. Admin/SuperAdmin hierarchy never bypasses; ownership
 * is created_by_user_id. CSRF is handled in the controller.
 */
final class HomeworkDefinitionManager
{
    public const FORM_TIMEZONE = 'Europe/Istanbul';

    private const CLASSROOM_ERROR = 'Listeden atandığın aktif bir sınıf seç.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FreshUserLoader $freshUsers,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
        private readonly HomeworkTeacherScope $scope,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createDraft(User $actor, HomeworkDraftInput $input): HomeworkDefinition
    {
        $actorId = $actor->getId();

        return $this->transactional(function () use ($actorId, $input): HomeworkDefinition|HomeworkDefinitionException {
            $freshActor = $this->freshActor($actorId);
            if (!$freshActor instanceof User) {
                return HomeworkDefinitionException::forbidden();
            }
            $now = UtcInstant::ensure($this->clock->now());
            [$title, $description, $dueAt, $errors] = $this->validateFields($input, $now);
            $classroomId = $this->parseClassroomId($input->classroomId);
            $assignment = $classroomId instanceof Uuid
                ? $this->scope->findAssignment($freshActor, $classroomId, true, true)
                : null;
            if (null === $assignment) {
                $errors['classroom'] = self::CLASSROOM_ERROR;
            }
            if ([] !== $errors || null === $title || null === $description || !$dueAt instanceof \DateTimeImmutable || null === $assignment) {
                return HomeworkDefinitionException::invalidInput($errors);
            }

            $definition = HomeworkDefinition::createDraft(
                $freshActor,
                $assignment->getTeacherMembership(),
                $assignment->getClassroom(),
                $title,
                $description,
                $dueAt,
                $now,
            );
            $this->entityManager->persist($definition);
            $this->entityManager->flush();
            $this->audit(SecurityAuditAction::HomeworkDefinitionDraftCreated, $freshActor, $definition);

            return $definition;
        });
    }

    public function updateDraft(User $actor, Uuid $definitionId, HomeworkDraftInput $input): HomeworkDefinition
    {
        $actorId = $actor->getId();

        return $this->transactional(function () use ($actorId, $definitionId, $input): HomeworkDefinition|HomeworkDefinitionException {
            $definition = $this->lockDefinition($definitionId);
            $freshActor = $this->freshActor($actorId);
            if (!$definition instanceof HomeworkDefinition) {
                return HomeworkDefinitionException::notFound();
            }
            if (!$freshActor instanceof User) {
                return HomeworkDefinitionException::forbidden();
            }
            if (!$definition->getCreatedByUser()->getId()->equals($freshActor->getId())) {
                return HomeworkDefinitionException::notFound();
            }
            // Current scope on the stored classroom first: ended assignment/membership => opaque deny.
            $current = $this->scope->findAssignment($freshActor, $definition->getClassroom()->getId(), true, true);
            if (null === $current) {
                return HomeworkDefinitionException::notFound();
            }
            if (HomeworkDefinitionStatus::Draft !== $definition->getStatus()) {
                return HomeworkDefinitionException::notEditable();
            }
            if (null === $input->expectedRevision || $input->expectedRevision !== $definition->getRevisionNumber()) {
                return HomeworkDefinitionException::staleRevision();
            }

            $now = UtcInstant::ensure($this->clock->now());
            [$title, $description, $dueAt, $errors] = $this->validateFields($input, $now);
            $classroomId = $this->parseClassroomId($input->classroomId);
            $target = null;
            if ($classroomId instanceof Uuid) {
                $target = $classroomId->equals($definition->getClassroom()->getId())
                    ? $current
                    : $this->scope->findAssignment($freshActor, $classroomId, true, true);
            }
            if (null === $target) {
                $errors['classroom'] = self::CLASSROOM_ERROR;
            }
            if ([] !== $errors || null === $title || null === $description || !$dueAt instanceof \DateTimeImmutable || null === $target) {
                return HomeworkDefinitionException::invalidInput($errors);
            }

            $definition->reviseDraft($target->getTeacherMembership(), $target->getClassroom(), $title, $description, $dueAt, $now);
            $this->entityManager->flush();
            $this->audit(SecurityAuditAction::HomeworkDefinitionDraftUpdated, $freshActor, $definition);

            return $definition;
        });
    }
    /**
     * Converts form wall-clock (Europe/Istanbul) to a UTC instant. Returns null when malformed.
     */
    public static function parseDue(string $date, string $time): ?\DateTimeImmutable
    {
        $date = trim($date);
        $time = trim($time);
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || 1 !== preg_match('/^\d{2}:\d{2}$/', $time)) {
            return null;
        }
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, new \DateTimeZone(self::FORM_TIMEZONE));
        if (!$local instanceof \DateTimeImmutable || $local->format('Y-m-d H:i') !== $date.' '.$time) {
            return null;
        }

        return UtcInstant::ensure($local);
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?\DateTimeImmutable, 3: array<string, string>}
     */
    private function validateFields(HomeworkDraftInput $input, \DateTimeImmutable $now): array
    {
        $errors = [];
        $title = null;
        $description = null;
        try {
            $title = HomeworkText::title($input->title);
        } catch (\InvalidArgumentException $exception) {
            $errors['title'] = $exception->getMessage();
        }
        try {
            $description = HomeworkText::description($input->description);
        } catch (\InvalidArgumentException $exception) {
            $errors['description'] = $exception->getMessage();
        }
        $dueAt = self::parseDue($input->dueDate, $input->dueTime);
        if (!$dueAt instanceof \DateTimeImmutable) {
            $errors['due'] = 'Geçerli bir son teslim tarihi ve saati gir.';
        } elseif ($dueAt <= $now) {
            $errors['due'] = 'Son teslim zamanı gelecekte olmalı.';
            $dueAt = null;
        }

        return [$title, $description, $dueAt, $errors];
    }

    private function parseClassroomId(string $raw): ?Uuid
    {
        $raw = trim($raw);
        if ('' === $raw || !Uuid::isValid($raw)) {
            return null;
        }

        return Uuid::fromString($raw);
    }

    private function lockDefinition(Uuid $definitionId): ?HomeworkDefinition
    {
        $definition = $this->entityManager->createQueryBuilder()
            ->select('h')
            ->from(HomeworkDefinition::class, 'h')
            ->andWhere('h.id = :id')
            ->setParameter('id', $definitionId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        return $definition instanceof HomeworkDefinition ? $definition : null;
    }

    private function freshActor(Uuid $actorId): ?User
    {
        $actor = $this->freshUsers->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
        return $actor instanceof User && $this->activeUsers->isActiveAndVerified($actor) ? $actor : null;
    }

    private function audit(SecurityAuditAction $action, User $actor, HomeworkDefinition $definition): void
    {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: [
                'resource_type' => 'homework_definition',
                'resource_id' => $definition->getId()->toRfc4122(),
                'institution_id' => $definition->getInstitution()->getId()->toRfc4122(),
                'classroom_id' => $definition->getClassroom()->getId()->toRfc4122(),
                'academic_year_id' => $definition->getAcademicYear()->getId()->toRfc4122(),
                'status' => $definition->getStatus()->value,
                'revision_number' => $definition->getRevisionNumber(),
            ],
            // Distinct per write: the recorder de-duplicates identical fingerprints within a request.
            correlationId: Uuid::v7()->toRfc4122(),
        ));
    }

    /**
     * Doctrine closes the EntityManager when a transaction callback throws. Expected domain
     * refusals are therefore returned from the callback and thrown only after the commit, so the
     * controller can still read and re-render within the same request.
     *
     * @param \Closure(): (HomeworkDefinition|HomeworkDefinitionException) $work
     */
    private function transactional(\Closure $work): HomeworkDefinition
    {
        try {
            $result = $this->entityManager->wrapInTransaction($work);
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw HomeworkDefinitionException::conflict();
        }
        if ($result instanceof HomeworkDefinitionException) {
            throw $result;
        }

        return $result;
    }
}

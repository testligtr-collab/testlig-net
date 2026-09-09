<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\Question;
use App\Entity\QuestionAnswerKey;
use App\Entity\QuestionRevision;
use App\Entity\QuestionRevisionAlignment;
use App\Entity\QuestionRevisionOption;
use App\Entity\QuestionRevisionPrimaryAlignmentGuard;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionScope;
use App\Enum\QuestionSourceType;
use App\Enum\QuestionStatus;
use App\Enum\QuestionType;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Exception\QuestionException;
use App\Question\Answer\QuestionTypeAnswerValidator;
use App\Question\Content\QuestionContentDocument;
use App\Question\Content\QuestionContentHasher;
use App\Question\Content\QuestionContentValidator;
use App\Repository\QuestionAnswerKeyRepository;
use App\Repository\QuestionRepository;
use App\Repository\QuestionRevisionAlignmentRepository;
use App\Repository\QuestionRevisionOptionRepository;
use App\Repository\QuestionRevisionPrimaryAlignmentGuardRepository;
use App\Repository\QuestionRevisionRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Question bank lifecycle. Content is append-only via immutable revisions.
 *
 * Lock order: Institution? → Subject → CurriculumProgram → Topic/Outcome → Question → Users(UUID asc) → Revision.
 */
final class QuestionManager
{
    public function __construct(
        private readonly QuestionRepository $questions,
        private readonly QuestionRevisionRepository $revisions,
        private readonly QuestionRevisionOptionRepository $options,
        private readonly QuestionAnswerKeyRepository $answerKeys,
        private readonly QuestionRevisionAlignmentRepository $alignments,
        private readonly QuestionRevisionPrimaryAlignmentGuardRepository $primaryGuards,
        private readonly QuestionContentValidator $contentValidator,
        private readonly QuestionTypeAnswerValidator $answerValidator,
        private readonly QuestionContentHasher $contentHasher,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array{stableKey: string, content: QuestionContentDocument|array<string, mixed>, position: int}> $options
     * @param array<string, mixed>                                                                                 $answerSpec
     * @param list<array{learningOutcome: CurriculumLearningOutcome, isPrimary: bool}>                             $alignments
     * @param QuestionContentDocument|array<string, mixed>                                                         $stem
     * @param array<string, mixed>|null                                                                            $explanation
     */
    public function createDraftQuestion(
        User $actor,
        QuestionScope $scope,
        ?Institution $institution,
        Subject $subject,
        GradeLevel $gradeLevel,
        QuestionType $type,
        QuestionContentDocument|array $stem,
        ?array $explanation,
        array $options,
        array $answerSpec,
        array $alignments,
        QuestionDifficulty $difficulty,
        string $reasonCode,
        ?int $estimatedSeconds = null,
        QuestionSourceType $sourceType = QuestionSourceType::Original,
        ?string $sourceReference = null,
    ): Question {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $stemDoc = $stem instanceof QuestionContentDocument ? $stem : QuestionContentDocument::fromArray($stem);
        $explanationDoc = null === $explanation ? null : QuestionContentDocument::fromArray($explanation);

        $actorId = $actor->getId();
        $subjectId = $subject->getId();
        $institutionId = $institution?->getId();
        $alignmentSpecs = $this->normalizeAlignmentSpecs($alignments);

        try {
            $question = $this->entityManager->wrapInTransaction(function () use (
                $actorId,
                $subjectId,
                $institutionId,
                $scope,
                $gradeLevel,
                $type,
                $stemDoc,
                $explanationDoc,
                $options,
                $answerSpec,
                $alignmentSpecs,
                $difficulty,
                $estimatedSeconds,
                $sourceType,
                $sourceReference,
                $reasonCode,
            ): Question {
                $lockedInstitution = null;
                if (null !== $institutionId) {
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $institutionId,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution) {
                        throw QuestionException::notFound();
                    }
                }

                $lockedSubject = $this->freshEntities->findFreshLockedSubject($subjectId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedSubject instanceof Subject || SubjectStatus::Archived === $lockedSubject->getStatus()) {
                    throw QuestionException::invalidInput('Subject is missing or archived.');
                }

                $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$freshActor instanceof User) {
                    throw QuestionException::userNotFound();
                }
                $this->assertActorMayCreate($freshActor, $scope, $lockedInstitution);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $question = Question::createDraft(
                    $scope,
                    $lockedInstitution,
                    $lockedSubject,
                    $gradeLevel,
                    $freshActor,
                    $now,
                );
                $this->questions->save($question, false);

                $this->persistRevisionBundle(
                    $question,
                    1,
                    $freshActor,
                    $type,
                    $stemDoc,
                    $explanationDoc,
                    $options,
                    $answerSpec,
                    $alignmentSpecs,
                    $difficulty,
                    $estimatedSeconds,
                    $sourceType,
                    $sourceReference,
                    $now,
                    allowDraftCurriculum: true,
                );

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::QuestionCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'question_manager',
                        'reason_code' => $reasonCode,
                        'question_id' => $question->getId()->toRfc4122(),
                        'revision_number' => 1,
                        'subject_id' => $lockedSubject->getId()->toRfc4122(),
                        'institution_id' => $lockedInstitution?->getId()->toRfc4122(),
                        'new_status' => $question->getStatus()->value,
                        'grade_level' => $gradeLevel->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $question;
            });
        } catch (UniqueConstraintViolationException) {
            throw QuestionException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw QuestionException::conflict();
        }

        $this->authCache->invalidateQuestion($question->getId());

        return $question;
    }

    /**
     * @param list<array{stableKey: string, content: QuestionContentDocument|array<string, mixed>, position: int}> $options
     * @param array<string, mixed>                                                                                 $answerSpec
     * @param list<array{learningOutcome: CurriculumLearningOutcome, isPrimary: bool}>                             $alignments
     * @param QuestionContentDocument|array<string, mixed>                                                         $stem
     * @param array<string, mixed>|null                                                                            $explanation
     */
    public function createRevision(
        Question $question,
        User $actor,
        QuestionType $type,
        QuestionContentDocument|array $stem,
        ?array $explanation,
        array $options,
        array $answerSpec,
        array $alignments,
        QuestionDifficulty $difficulty,
        string $reasonCode,
        ?int $estimatedSeconds = null,
        QuestionSourceType $sourceType = QuestionSourceType::Original,
        ?string $sourceReference = null,
    ): QuestionRevision {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $stemDoc = $stem instanceof QuestionContentDocument ? $stem : QuestionContentDocument::fromArray($stem);
        $explanationDoc = null === $explanation ? null : QuestionContentDocument::fromArray($explanation);
        $alignmentSpecs = $this->normalizeAlignmentSpecs($alignments);

        $questionId = $question->getId();
        $actorId = $actor->getId();

        try {
            $revision = $this->entityManager->wrapInTransaction(function () use (
                $questionId,
                $actorId,
                $type,
                $stemDoc,
                $explanationDoc,
                $options,
                $answerSpec,
                $alignmentSpecs,
                $difficulty,
                $estimatedSeconds,
                $sourceType,
                $sourceReference,
                $reasonCode,
            ): QuestionRevision {
                $lockedQuestion = $this->lockQuestionWithScope($questionId);
                $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$freshActor instanceof User) {
                    throw QuestionException::userNotFound();
                }
                $this->assertActorMayManageContent($freshActor, $lockedQuestion);

                if (!$lockedQuestion->getStatus()->allowsNewRevision()) {
                    throw QuestionException::invalidTransition();
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $revisionNumber = $lockedQuestion->bumpRevisionNumber($now);

                $revision = $this->persistRevisionBundle(
                    $lockedQuestion,
                    $revisionNumber,
                    $freshActor,
                    $type,
                    $stemDoc,
                    $explanationDoc,
                    $options,
                    $answerSpec,
                    $alignmentSpecs,
                    $difficulty,
                    $estimatedSeconds,
                    $sourceType,
                    $sourceReference,
                    $now,
                    allowDraftCurriculum: true,
                );
                $this->questions->save($lockedQuestion, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::QuestionRevisionCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'question_manager',
                        'reason_code' => $reasonCode,
                        'question_id' => $lockedQuestion->getId()->toRfc4122(),
                        'revision_id' => $revision->getId()->toRfc4122(),
                        'revision_number' => $revisionNumber,
                        'new_status' => $lockedQuestion->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $revision;
            });
        } catch (UniqueConstraintViolationException) {
            throw QuestionException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw QuestionException::conflict();
        }

        $this->authCache->invalidateQuestion($questionId);

        return $revision;
    }

    public function submitForReview(Question $question, User $actor, string $reasonCode): void
    {
        $this->transition($question, $actor, $reasonCode, SecurityAuditAction::QuestionSubmittedForReview, static function (Question $q, \DateTimeImmutable $now): void {
            $q->submitForReview($now);
        }, requireManage: true);
    }

    public function returnToDraft(Question $question, User $actor, string $reasonCode): void
    {
        $this->transition($question, $actor, $reasonCode, SecurityAuditAction::QuestionReturnedToDraft, static function (Question $q, \DateTimeImmutable $now): void {
            $q->returnToDraft($now);
        }, requireReview: true);
    }

    public function publish(Question $question, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $questionId = $question->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use ($questionId, $actorId, $reasonCode): void {
                $lockedQuestion = $this->lockQuestionWithScope($questionId);
                $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$freshActor instanceof User) {
                    throw QuestionException::userNotFound();
                }
                $this->assertActorMayPublish($freshActor, $lockedQuestion);

                $revision = $this->revisions->findForQuestionNumber(
                    $lockedQuestion,
                    $lockedQuestion->getCurrentRevisionNumber(),
                );
                if (!$revision instanceof QuestionRevision) {
                    throw QuestionException::notFound();
                }
                if ($revision->getCreatedBy()->getId()->equals($freshActor->getId())) {
                    throw QuestionException::reviewSeparation();
                }

                $this->assertPublishableRevision($lockedQuestion, $revision);

                $oldStatus = $lockedQuestion->getStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $lockedQuestion->publish($now);
                $this->questions->save($lockedQuestion, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::QuestionPublished,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'question_manager',
                        'reason_code' => $reasonCode,
                        'question_id' => $lockedQuestion->getId()->toRfc4122(),
                        'revision_id' => $revision->getId()->toRfc4122(),
                        'revision_number' => $revision->getRevisionNumber(),
                        'old_status' => $oldStatus,
                        'new_status' => $lockedQuestion->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw QuestionException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw QuestionException::conflict();
        }

        $this->authCache->invalidateQuestion($questionId);
    }

    public function archive(Question $question, User $actor, string $reasonCode): void
    {
        $this->transition($question, $actor, $reasonCode, SecurityAuditAction::QuestionArchived, static function (Question $q, \DateTimeImmutable $now): void {
            $q->archive($now);
        }, requireManage: true);
    }

    /**
     * @param callable(Question, \DateTimeImmutable): void $mutator
     */
    private function transition(
        Question $question,
        User $actor,
        string $reasonCode,
        SecurityAuditAction $action,
        callable $mutator,
        bool $requireManage = false,
        bool $requireReview = false,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $questionId = $question->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $questionId,
                $actorId,
                $reasonCode,
                $action,
                $mutator,
                $requireManage,
                $requireReview,
            ): void {
                $lockedQuestion = $this->lockQuestionWithScope($questionId);
                $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$freshActor instanceof User) {
                    throw QuestionException::userNotFound();
                }
                if ($requireReview) {
                    $this->assertActorMayReview($freshActor, $lockedQuestion);
                } elseif ($requireManage) {
                    $this->assertActorMayManageContent($freshActor, $lockedQuestion);
                }

                $oldStatus = $lockedQuestion->getStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $mutator($lockedQuestion, $now);
                $this->questions->save($lockedQuestion, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $action,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'question_manager',
                        'reason_code' => $reasonCode,
                        'question_id' => $lockedQuestion->getId()->toRfc4122(),
                        'revision_number' => $lockedQuestion->getCurrentRevisionNumber(),
                        'old_status' => $oldStatus,
                        'new_status' => $lockedQuestion->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            throw QuestionException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw QuestionException::conflict();
        }

        $this->authCache->invalidateQuestion($questionId);
    }

    /**
     * @param list<array{stableKey: string, content: QuestionContentDocument|array<string, mixed>, position: int}> $options
     * @param array<string, mixed>                                                                                 $answerSpec
     * @param list<array{outcomeId: Uuid, isPrimary: bool}>                                                        $alignmentSpecs
     */
    private function persistRevisionBundle(
        Question $question,
        int $revisionNumber,
        User $author,
        QuestionType $type,
        QuestionContentDocument $stem,
        ?QuestionContentDocument $explanation,
        array $options,
        array $answerSpec,
        array $alignmentSpecs,
        QuestionDifficulty $difficulty,
        ?int $estimatedSeconds,
        QuestionSourceType $sourceType,
        ?string $sourceReference,
        \DateTimeImmutable $now,
        bool $allowDraftCurriculum,
    ): QuestionRevision {
        $this->contentValidator->validate($stem);
        if (null !== $explanation) {
            $this->contentValidator->validate($explanation);
        }
        $normalized = $this->answerValidator->validateAndNormalize($type, $options, $answerSpec);
        if ([] === $alignmentSpecs) {
            throw QuestionException::alignmentInvalid('At least one alignment is required.');
        }
        $primaryCount = 0;
        foreach ($alignmentSpecs as $spec) {
            if ($spec['isPrimary']) {
                ++$primaryCount;
            }
        }
        if (1 !== $primaryCount) {
            throw QuestionException::alignmentInvalid('Exactly one primary alignment is required.');
        }

        $hashPayload = [
            'type' => $type->value,
            'stem' => $stem->toArray(),
            'explanation' => $explanation?->toArray(),
            'options' => $normalized['options'],
            'answer' => $normalized['answerPayload'],
            'difficulty' => $difficulty->value,
            'estimatedSeconds' => $estimatedSeconds,
            'sourceType' => $sourceType->value,
            'sourceReference' => $sourceReference,
            'alignments' => array_map(
                static fn (array $s): array => [
                    'learningOutcomeId' => $s['outcomeId']->toRfc4122(),
                    'isPrimary' => $s['isPrimary'],
                ],
                $alignmentSpecs,
            ),
        ];
        $contentHash = $this->contentHasher->hash($hashPayload);

        $revision = QuestionRevision::create(
            $question,
            $revisionNumber,
            $type,
            $stem->toArray(),
            $explanation?->toArray(),
            $difficulty,
            $estimatedSeconds,
            $sourceType,
            $sourceReference,
            $author,
            $contentHash,
            QuestionContentDocument::SCHEMA_VERSION,
            $now,
        );
        $this->revisions->save($revision, false);

        foreach ($normalized['options'] as $option) {
            $this->options->save(QuestionRevisionOption::create(
                $revision,
                $option['stableKey'],
                $option['content'],
                $option['position'],
            ), false);
        }

        $this->answerKeys->save(QuestionAnswerKey::create(
            $revision,
            $type,
            $normalized['answerPayload'],
            $now,
        ), false);

        foreach ($alignmentSpecs as $spec) {
            $outcome = $this->freshEntities->findFreshLockedCurriculumLearningOutcome(
                $spec['outcomeId'],
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$outcome instanceof CurriculumLearningOutcome) {
                throw QuestionException::alignmentInvalid('Learning outcome not found.');
            }
            $program = $outcome->getCurriculumProgram();
            $this->freshEntities->findFreshLockedCurriculumProgram($program->getId(), LockMode::PESSIMISTIC_WRITE);
            $topic = $outcome->getTopic();
            $this->freshEntities->findFreshLockedCurriculumTopic($topic->getId(), LockMode::PESSIMISTIC_WRITE);

            if (!$program->getSubject()->getId()->equals($question->getSubject()->getId())) {
                throw QuestionException::alignmentInvalid('Alignment subject mismatch.');
            }
            if ($program->getGradeLevel() !== $question->getGradeLevel()) {
                throw QuestionException::alignmentInvalid('Alignment grade mismatch.');
            }
            if (!$allowDraftCurriculum && CurriculumStatus::Published !== $program->getStatus()) {
                throw QuestionException::curriculumNotPublished();
            }
            if (!\in_array($program->getStatus(), [CurriculumStatus::Draft, CurriculumStatus::Published], true)) {
                throw QuestionException::alignmentInvalid('Curriculum status does not allow alignment.');
            }

            $alignment = QuestionRevisionAlignment::create(
                $revision,
                $program,
                $program->getSubject(),
                $topic,
                $outcome,
                $spec['isPrimary'],
                $now,
            );
            $this->alignments->save($alignment, false);
            if ($spec['isPrimary']) {
                $this->primaryGuards->save(QuestionRevisionPrimaryAlignmentGuard::bind($revision, $alignment), false);
            }
        }

        return $revision;
    }

    private function assertPublishableRevision(Question $question, QuestionRevision $revision): void
    {
        $answerKey = $this->answerKeys->findOneByRevision($revision);
        if (!$answerKey instanceof QuestionAnswerKey) {
            throw QuestionException::answerInvalid('Answer key missing.');
        }
        $options = $this->options->findByRevision($revision);
        $optionSpecs = [];
        foreach ($options as $option) {
            $optionSpecs[] = [
                'stableKey' => $option->getStableKey(),
                'content' => $option->getContent(),
                'position' => $option->getPosition(),
            ];
        }
        $payload = $answerKey->getAnswerPayload();
        $answerSpec = match ($revision->getType()) {
            QuestionType::SingleChoice => ['correctStableKey' => $payload['correctStableKey'] ?? null],
            QuestionType::MultipleChoice => ['correctStableKeys' => $payload['correctStableKeys'] ?? []],
            QuestionType::TrueFalse => ['correct' => $payload['correct'] ?? null],
            QuestionType::Numeric => [
                'value' => $payload['value'] ?? null,
                'tolerance' => $payload['tolerance'] ?? null,
            ],
            QuestionType::ShortAnswer => [
                'acceptedAnswers' => $payload['acceptedAnswers'] ?? [],
                'caseSensitive' => $payload['caseSensitive'] ?? false,
            ],
        };
        $this->answerValidator->validateAndNormalize($revision->getType(), $optionSpecs, $answerSpec);

        $alignments = $this->alignments->findByRevision($revision);
        if ([] === $alignments) {
            throw QuestionException::alignmentInvalid('At least one alignment is required.');
        }
        $primary = 0;
        foreach ($alignments as $alignment) {
            if ($alignment->isPrimary()) {
                ++$primary;
            }
            $program = $alignment->getCurriculumProgram();
            if (CurriculumStatus::Published !== $program->getStatus()) {
                throw QuestionException::curriculumNotPublished();
            }
            if (!$program->getSubject()->getId()->equals($question->getSubject()->getId())) {
                throw QuestionException::alignmentInvalid('Subject mismatch on publish.');
            }
            if ($program->getGradeLevel() !== $question->getGradeLevel()) {
                throw QuestionException::alignmentInvalid('Grade mismatch on publish.');
            }
        }
        if (1 !== $primary) {
            throw QuestionException::alignmentInvalid('Exactly one primary alignment required on publish.');
        }
    }

    /**
     * @param list<array{learningOutcome?: mixed, isPrimary?: mixed}> $alignments
     *
     * @return list<array{outcomeId: Uuid, isPrimary: bool}>
     */
    private function normalizeAlignmentSpecs(array $alignments): array
    {
        $specs = [];
        foreach ($alignments as $row) {
            $outcome = $row['learningOutcome'] ?? null;
            $isPrimary = $row['isPrimary'] ?? false;
            if (!$outcome instanceof CurriculumLearningOutcome || !\is_bool($isPrimary)) {
                throw QuestionException::alignmentInvalid('Invalid alignment specification.');
            }
            $specs[] = ['outcomeId' => $outcome->getId(), 'isPrimary' => $isPrimary];
        }

        return $specs;
    }

    private function lockQuestionWithScope(Uuid $questionId): Question
    {
        $lockedQuestion = $this->freshEntities->findFreshLockedQuestion($questionId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedQuestion instanceof Question) {
            throw QuestionException::notFound();
        }
        if (QuestionScope::Institution === $lockedQuestion->getScope()) {
            $institution = $lockedQuestion->getInstitution();
            if (!$institution instanceof Institution) {
                throw QuestionException::scopeMismatch();
            }
            $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                $institution->getId(),
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$lockedInstitution instanceof Institution) {
                throw QuestionException::notFound();
            }
        }
        $this->freshEntities->findFreshLockedSubject(
            $lockedQuestion->getSubject()->getId(),
            LockMode::PESSIMISTIC_WRITE,
        );

        return $lockedQuestion;
    }

    private function assertActorMayCreate(User $actor, QuestionScope $scope, ?Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw QuestionException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }

        if (QuestionScope::Platform === $scope) {
            if ($this->hasAnyRole($actor, [UserRole::HeadTeacher, UserRole::ExpertTeacher, UserRole::Teacher])) {
                return;
            }
            throw QuestionException::unauthorized();
        }

        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw QuestionException::unauthorized();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()) {
            throw QuestionException::unauthorized();
        }
        $role = $membership->getRole();
        if (!\in_array($role, [
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
            InstitutionMembershipRole::Teacher,
        ], true)) {
            throw QuestionException::unauthorized();
        }
    }

    private function assertActorMayManageContent(User $actor, Question $question): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw QuestionException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }

        if (QuestionScope::Platform === $question->getScope()) {
            if ($this->hasAnyRole($actor, [UserRole::HeadTeacher, UserRole::ExpertTeacher])) {
                return;
            }
            if ($this->hasAnyRole($actor, [UserRole::Teacher])
                && $question->getCreatedBy()->getId()->equals($actor->getId())
                && \in_array($question->getStatus(), [QuestionStatus::Draft, QuestionStatus::InReview], true)) {
                return;
            }
            throw QuestionException::unauthorized();
        }

        $institution = $question->getInstitution();
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw QuestionException::unauthorized();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()) {
            throw QuestionException::unauthorized();
        }
        if (\in_array($membership->getRole(), [InstitutionMembershipRole::Owner, InstitutionMembershipRole::Manager], true)) {
            return;
        }
        if (InstitutionMembershipRole::Teacher === $membership->getRole()
            && $question->getCreatedBy()->getId()->equals($actor->getId())) {
            return;
        }

        throw QuestionException::unauthorized();
    }

    private function assertActorMayReview(User $actor, Question $question): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw QuestionException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }
        if (QuestionScope::Platform === $question->getScope()) {
            if ($this->hasAnyRole($actor, [UserRole::HeadTeacher, UserRole::ExpertTeacher])) {
                return;
            }
            throw QuestionException::unauthorized();
        }
        $institution = $question->getInstitution();
        if (!$institution instanceof Institution) {
            throw QuestionException::unauthorized();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()) {
            throw QuestionException::unauthorized();
        }
        if (\in_array($membership->getRole(), [InstitutionMembershipRole::Owner, InstitutionMembershipRole::Manager], true)) {
            return;
        }

        throw QuestionException::unauthorized();
    }

    private function assertActorMayPublish(User $actor, Question $question): void
    {
        // ADMIN/MODERATOR do not auto-gain publish rights.
        $this->assertActorMayReview($actor, $question);
    }

    /**
     * @param list<UserRole> $roles
     */
    private function hasAnyRole(User $actor, array $roles): bool
    {
        $actorRoles = $actor->getRoles();
        foreach ($roles as $role) {
            if (\in_array($role->value, $actorRoles, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw QuestionException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }
}

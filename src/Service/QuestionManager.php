<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
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
use App\Enum\CurriculumContentStatus;
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
use App\Question\Answer\QuestionAnswerIntegrityHasher;
use App\Question\Answer\QuestionTypeAnswerValidator;
use App\Question\Content\QuestionContentDocument;
use App\Question\Content\QuestionContentHasher;
use App\Question\Content\QuestionContentValidator;
use App\Question\Content\QuestionPublicContentHashBuilder;
use App\Question\Content\QuestionSourceReferencePolicy;
use App\Repository\QuestionAnswerKeyRepository;
use App\Repository\QuestionRepository;
use App\Repository\QuestionRevisionAlignmentRepository;
use App\Repository\QuestionRevisionOptionRepository;
use App\Repository\QuestionRevisionPrimaryAlignmentGuardRepository;
use App\Repository\QuestionRevisionRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Question bank lifecycle. Content is append-only via immutable revisions.
 *
 * Lock order (all mutation methods):
 * 1. Locksless DBAL snapshot of question scope fields when the question exists
 * 2. Institution (if institution scope)
 * 3. Subject
 * 4. CurriculumProgram → CurriculumTopic → LearningOutcome (UUID ascending within each tier)
 *    when alignment outcome IDs are known
 * 5. Question PESSIMISTIC_WRITE + HINT_REFRESH; revalidate snapshot scope/institution/subject
 * 6. Users UUID ascending (actor, and createdBy when needed for auth checks)
 * 7. Revision / options / answer key / alignments last
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
        private readonly QuestionPublicContentHashBuilder $publicContentHashBuilder,
        private readonly QuestionAnswerIntegrityHasher $answerIntegrityHasher,
        private readonly QuestionSourceReferencePolicy $sourceReferencePolicy,
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
        $outcomeIds = array_map(static fn (array $spec): Uuid => $spec['outcomeId'], $alignmentSpecs);

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
                $outcomeIds,
                $difficulty,
                $estimatedSeconds,
                $sourceType,
                $sourceReference,
                $reasonCode,
            ): Question {
                // Lock order (create): Institution? → Subject → Curriculum → Actor → Question → revision bundle.
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

                $this->lockCurriculumForOutcomeIds($outcomeIds);

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
        } catch (QuestionException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException) {
            throw QuestionException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw QuestionException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
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
        $outcomeIds = array_map(static fn (array $spec): Uuid => $spec['outcomeId'], $alignmentSpecs);

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
                $outcomeIds,
                $difficulty,
                $estimatedSeconds,
                $sourceType,
                $sourceReference,
                $reasonCode,
            ): QuestionRevision {
                // Curriculum locked before question when outcome IDs are known from the request.
                $lockedQuestion = $this->lockQuestionWithScope($questionId, $outcomeIds);
                $users = $this->freshEntities->findFreshLockedUsers(
                    [$actorId, $lockedQuestion->getCreatedBy()->getId()],
                    LockMode::PESSIMISTIC_READ,
                );
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
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
        } catch (QuestionException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException) {
            throw QuestionException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw QuestionException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
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
                // 1–2. Locksless snapshots (question scope + current-revision alignments).
                $snapshot = $this->fetchQuestionScopeSnapshot($questionId);
                if (null === $snapshot) {
                    throw QuestionException::notFound();
                }
                $alignmentPreview = $this->fetchCurrentRevisionAlignmentCurriculumIds($questionId);
                $outcomeIds = array_values(array_unique(array_map(
                    static fn (array $row): string => $row['outcomeId']->toRfc4122(),
                    $alignmentPreview,
                )));
                $outcomeUuids = array_map(static fn (string $id): Uuid => Uuid::fromString($id), $outcomeIds);

                // 3. Institution? → Subject → Programs → Topics → Outcomes (sorted UUIDs).
                if (QuestionScope::Institution->value === $snapshot['scope']) {
                    if (null === $snapshot['institution_id']) {
                        throw QuestionException::scopeMismatch();
                    }
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        Uuid::fromString($snapshot['institution_id']),
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution) {
                        throw QuestionException::notFound();
                    }
                }

                $lockedSubject = $this->freshEntities->findFreshLockedSubject(
                    Uuid::fromString($snapshot['subject_id']),
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedSubject instanceof Subject) {
                    throw QuestionException::notFound();
                }

                $this->lockCurriculumForOutcomeIds($outcomeUuids);

                // 4. Lock Question; revalidate full snapshot.
                $lockedQuestion = $this->freshEntities->findFreshLockedQuestion(
                    $questionId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedQuestion instanceof Question) {
                    throw QuestionException::notFound();
                }
                $this->assertQuestionSnapshotUnchanged($lockedQuestion, $snapshot, full: true);

                // 5. Lock Users (actor).
                $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$freshActor instanceof User) {
                    throw QuestionException::userNotFound();
                }
                $this->assertActorMayPublish($freshActor, $lockedQuestion);

                // 6. Fresh-load current revision with HINT_REFRESH + lock; verify ownership.
                $revisionId = $this->fetchRevisionIdForQuestionNumber(
                    $questionId,
                    $lockedQuestion->getCurrentRevisionNumber(),
                );
                if (null === $revisionId) {
                    throw QuestionException::notFound();
                }
                $revision = $this->freshEntities->findFreshLockedQuestionRevision(
                    $revisionId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$revision instanceof QuestionRevision) {
                    throw QuestionException::notFound();
                }
                if (!$revision->getQuestion()->getId()->equals($lockedQuestion->getId())) {
                    throw QuestionException::conflict();
                }

                // 7. Publishability checks against freshly reloaded rows.
                $this->assertPublishableRevisionFresh($lockedQuestion, $revision, $freshActor);

                // 8. Publish + audit same TX; cache invalidate only after commit.
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
        } catch (QuestionException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException) {
            throw QuestionException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw QuestionException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
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
                $userIds = [$actorId];
                if ($requireManage) {
                    $userIds[] = $lockedQuestion->getCreatedBy()->getId();
                }
                $users = $this->freshEntities->findFreshLockedUsers($userIds, LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
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
        } catch (QuestionException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException) {
            throw QuestionException::conflict();
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw QuestionException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
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
        try {
            $this->sourceReferencePolicy->assertValid($sourceReference);
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

            $outcomeIds = array_map(static fn (array $spec): Uuid => $spec['outcomeId'], $alignmentSpecs);
            // Curriculum must already be locked by the caller before Question. Refresh only — do not
            // acquire new WRITE locks after Question (deadlock risk vs documented order).
            $lockedOutcomes = $this->refreshCurriculumOutcomes($outcomeIds);

            $publicAlignments = array_map(
                static fn (array $s): array => [
                    'learningOutcomeId' => $s['outcomeId']->toRfc4122(),
                    'isPrimary' => $s['isPrimary'],
                ],
                $alignmentSpecs,
            );
            $hashPayload = $this->publicContentHashBuilder->build(
                $type->value,
                $stem->toArray(),
                $explanation?->toArray(),
                $normalized['options'],
                $difficulty->value,
                $estimatedSeconds,
                $sourceType->value,
                $sourceReference,
                $publicAlignments,
                QuestionContentDocument::SCHEMA_VERSION,
            );
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

            $answerIntegrityHmac = $this->answerIntegrityHasher->hash(
                $normalized['answerPayload'],
                $type,
                $revision->getId(),
            );
            $this->answerKeys->save(QuestionAnswerKey::create(
                $revision,
                $type,
                $normalized['answerPayload'],
                $answerIntegrityHmac,
                $now,
            ), false);

            foreach ($alignmentSpecs as $spec) {
                $outcome = $lockedOutcomes[$spec['outcomeId']->toRfc4122()] ?? null;
                if (!$outcome instanceof CurriculumLearningOutcome) {
                    throw QuestionException::alignmentInvalid('Learning outcome not found.');
                }
                $program = $outcome->getCurriculumProgram();
                $topic = $outcome->getTopic();

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
        } catch (QuestionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }
    }

    private function assertPublishableRevisionFresh(Question $question, QuestionRevision $revision, User $publisher): void
    {
        $answerKey = $this->findFreshAnswerKeyForRevision($revision);
        if (!$answerKey instanceof QuestionAnswerKey) {
            throw QuestionException::answerInvalid('Answer key missing.');
        }
        if ($answerKey->getAnswerType() !== $revision->getType()) {
            throw QuestionException::answerInvalid('Answer key type does not match revision type.');
        }

        // Integrity before answer-policy validation so tampered keys never reach type policies.
        $this->answerIntegrityHasher->verify(
            $answerKey->getAnswerIntegrityHmac(),
            $answerKey->getAnswerPayload(),
            $answerKey->getAnswerType(),
            $revision->getId(),
        );

        $options = $this->findFreshOptionsForRevision($revision);
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

        $alignments = $this->findFreshAlignmentsForRevision($revision);
        if ([] === $alignments) {
            throw QuestionException::alignmentInvalid('At least one alignment is required.');
        }

        $programIds = [];
        $topicIds = [];
        $outcomeIds = [];
        $primary = 0;
        foreach ($alignments as $alignment) {
            if ($alignment->isPrimary()) {
                ++$primary;
            }
            $programIds[$alignment->getCurriculumProgram()->getId()->toRfc4122()] = $alignment->getCurriculumProgram()->getId();
            $topicIds[$alignment->getCurriculumTopic()->getId()->toRfc4122()] = $alignment->getCurriculumTopic()->getId();
            $outcomeIds[$alignment->getLearningOutcome()->getId()->toRfc4122()] = $alignment->getLearningOutcome()->getId();
        }
        if (1 !== $primary) {
            throw QuestionException::alignmentInvalid('Exactly one primary alignment required on publish.');
        }

        ksort($programIds);
        ksort($topicIds);
        ksort($outcomeIds);

        // Publish path already locked curriculum before Question; refresh without escalating locks.
        $programs = [];
        foreach ($programIds as $key => $programId) {
            $program = $this->freshEntities->findFreshLockedCurriculumProgram($programId, LockMode::NONE);
            if (!$program instanceof CurriculumProgram) {
                throw QuestionException::alignmentInvalid('Curriculum program not found.');
            }
            $programs[$key] = $program;
        }
        $topics = [];
        foreach ($topicIds as $key => $topicId) {
            $topic = $this->freshEntities->findFreshLockedCurriculumTopic($topicId, LockMode::NONE);
            if (!$topic instanceof CurriculumTopic) {
                throw QuestionException::alignmentInvalid('Curriculum topic not found.');
            }
            $topics[$key] = $topic;
        }
        $outcomes = [];
        foreach ($outcomeIds as $key => $outcomeId) {
            $outcome = $this->freshEntities->findFreshLockedCurriculumLearningOutcome(
                $outcomeId,
                LockMode::NONE,
            );
            if (!$outcome instanceof CurriculumLearningOutcome) {
                throw QuestionException::alignmentInvalid('Learning outcome not found.');
            }
            $outcomes[$key] = $outcome;
        }

        foreach ($alignments as $alignment) {
            $program = $programs[$alignment->getCurriculumProgram()->getId()->toRfc4122()];
            $topic = $topics[$alignment->getCurriculumTopic()->getId()->toRfc4122()];
            $outcome = $outcomes[$alignment->getLearningOutcome()->getId()->toRfc4122()];

            if (CurriculumStatus::Published !== $program->getStatus()) {
                throw QuestionException::curriculumNotPublished();
            }
            if (!$program->getSubject()->getId()->equals($question->getSubject()->getId())) {
                throw QuestionException::alignmentInvalid('Subject mismatch on publish.');
            }
            if ($program->getGradeLevel() !== $question->getGradeLevel()) {
                throw QuestionException::alignmentInvalid('Grade mismatch on publish.');
            }
            if (CurriculumContentStatus::Active !== $topic->getStatus()) {
                throw QuestionException::alignmentInvalid('Curriculum topic must be active on publish.');
            }
            if (CurriculumContentStatus::Active !== $outcome->getStatus()) {
                throw QuestionException::alignmentInvalid('Learning outcome must be active on publish.');
            }
        }

        if ($publisher->getId()->equals($revision->getCreatedBy()->getId())) {
            throw QuestionException::reviewSeparation();
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

    /**
     * Must not lock Question before Institution/Subject. Uses a locksless DBAL snapshot first.
     *
     * @param list<Uuid>|null $curriculumOutcomeIds when known, locked after Subject and before Question
     */
    private function lockQuestionWithScope(Uuid $questionId, ?array $curriculumOutcomeIds = null): Question
    {
        $snapshot = $this->fetchQuestionScopeSnapshot($questionId);
        if (null === $snapshot) {
            throw QuestionException::notFound();
        }

        if (QuestionScope::Institution->value === $snapshot['scope']) {
            if (null === $snapshot['institution_id']) {
                throw QuestionException::scopeMismatch();
            }
            $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                Uuid::fromString($snapshot['institution_id']),
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$lockedInstitution instanceof Institution) {
                throw QuestionException::notFound();
            }
        }

        $lockedSubject = $this->freshEntities->findFreshLockedSubject(
            Uuid::fromString($snapshot['subject_id']),
            LockMode::PESSIMISTIC_WRITE,
        );
        if (!$lockedSubject instanceof Subject) {
            throw QuestionException::notFound();
        }

        if (null !== $curriculumOutcomeIds) {
            $this->lockCurriculumForOutcomeIds($curriculumOutcomeIds);
        }

        $lockedQuestion = $this->freshEntities->findFreshLockedQuestion($questionId, LockMode::PESSIMISTIC_WRITE);
        if (!$lockedQuestion instanceof Question) {
            throw QuestionException::notFound();
        }
        $this->assertQuestionSnapshotUnchanged($lockedQuestion, $snapshot, full: false);

        return $lockedQuestion;
    }

    /**
     * @return array{
     *     id: string,
     *     scope: string,
     *     institution_id: ?string,
     *     subject_id: string,
     *     grade_level: int,
     *     status: string,
     *     current_revision_number: int,
     *     created_by_id: string
     * }|null
     */
    private function fetchQuestionScopeSnapshot(Uuid $questionId): ?array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT id, scope, institution_id, subject_id, grade_level, status, current_revision_number, created_by_id
             FROM questions WHERE id = ?',
            [$questionId->toBinary()],
            [ParameterType::BINARY],
        );
        if (false === $row) {
            return null;
        }

        return [
            'id' => $this->uuidFromDb($row['id'])->toRfc4122(),
            'scope' => (string) $row['scope'],
            'institution_id' => null === $row['institution_id'] ? null : $this->uuidFromDb($row['institution_id'])->toRfc4122(),
            'subject_id' => $this->uuidFromDb($row['subject_id'])->toRfc4122(),
            'grade_level' => (int) $row['grade_level'],
            'status' => (string) $row['status'],
            'current_revision_number' => (int) $row['current_revision_number'],
            'created_by_id' => $this->uuidFromDb($row['created_by_id'])->toRfc4122(),
        ];
    }

    /**
     * @return list<array{programId: Uuid, topicId: Uuid, outcomeId: Uuid, alignmentId: Uuid, isPrimary: bool}>
     */
    private function fetchCurrentRevisionAlignmentCurriculumIds(Uuid $questionId): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT a.id AS alignment_id,
                    a.curriculum_program_id AS program_id,
                    a.curriculum_topic_id AS topic_id,
                    a.learning_outcome_id AS outcome_id,
                    a.is_primary AS is_primary
             FROM questions q
             INNER JOIN question_revisions r
                ON r.question_id = q.id AND r.revision_number = q.current_revision_number
             INNER JOIN question_revision_alignments a ON a.revision_id = r.id
             WHERE q.id = ?',
            [$questionId->toBinary()],
            [ParameterType::BINARY],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'programId' => $this->uuidFromDb($row['program_id']),
                'topicId' => $this->uuidFromDb($row['topic_id']),
                'outcomeId' => $this->uuidFromDb($row['outcome_id']),
                'alignmentId' => $this->uuidFromDb($row['alignment_id']),
                'isPrimary' => (bool) $row['is_primary'],
            ];
        }

        return $result;
    }

    /**
     * Locks CurriculumProgram → Topic → LearningOutcome in UUID-ascending order within each tier.
     *
     * @param list<Uuid> $outcomeIds
     *
     * @return array<string, CurriculumLearningOutcome>
     */
    private function lockCurriculumForOutcomeIds(array $outcomeIds): array
    {
        if ([] === $outcomeIds) {
            return [];
        }

        $uniqueOutcomeKeys = [];
        foreach ($outcomeIds as $outcomeId) {
            $uniqueOutcomeKeys[$outcomeId->toRfc4122()] = $outcomeId;
        }
        ksort($uniqueOutcomeKeys);

        $programIds = [];
        $topicIds = [];
        $placeholders = [];
        $params = [];
        $types = [];
        foreach (array_values($uniqueOutcomeKeys) as $i => $outcomeId) {
            $placeholders[] = '?';
            $params[] = $outcomeId->toBinary();
            $types[] = ParameterType::BINARY;
            unset($i);
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, topic_id, curriculum_program_id
             FROM curriculum_learning_outcomes
             WHERE id IN ('.implode(', ', $placeholders).')',
            $params,
            $types,
        );
        if (\count($rows) !== \count($uniqueOutcomeKeys)) {
            throw QuestionException::alignmentInvalid('Learning outcome not found.');
        }

        foreach ($rows as $row) {
            $programIds[$this->uuidFromDb($row['curriculum_program_id'])->toRfc4122()] = $this->uuidFromDb($row['curriculum_program_id']);
            $topicIds[$this->uuidFromDb($row['topic_id'])->toRfc4122()] = $this->uuidFromDb($row['topic_id']);
        }
        ksort($programIds);
        ksort($topicIds);

        foreach ($programIds as $programId) {
            $program = $this->freshEntities->findFreshLockedCurriculumProgram($programId, LockMode::PESSIMISTIC_WRITE);
            if (!$program instanceof CurriculumProgram) {
                throw QuestionException::alignmentInvalid('Curriculum program not found.');
            }
        }
        foreach ($topicIds as $topicId) {
            $topic = $this->freshEntities->findFreshLockedCurriculumTopic($topicId, LockMode::PESSIMISTIC_WRITE);
            if (!$topic instanceof CurriculumTopic) {
                throw QuestionException::alignmentInvalid('Curriculum topic not found.');
            }
        }

        $lockedOutcomes = [];
        foreach ($uniqueOutcomeKeys as $key => $outcomeId) {
            $outcome = $this->freshEntities->findFreshLockedCurriculumLearningOutcome(
                $outcomeId,
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$outcome instanceof CurriculumLearningOutcome) {
                throw QuestionException::alignmentInvalid('Learning outcome not found.');
            }
            $lockedOutcomes[$key] = $outcome;
        }

        return $lockedOutcomes;
    }

    /**
     * Refresh outcomes already locked earlier in the transaction (no new WRITE locks).
     *
     * @param list<Uuid> $outcomeIds
     *
     * @return array<string, CurriculumLearningOutcome>
     */
    private function refreshCurriculumOutcomes(array $outcomeIds): array
    {
        $lockedOutcomes = [];
        $keys = [];
        foreach ($outcomeIds as $outcomeId) {
            $keys[$outcomeId->toRfc4122()] = $outcomeId;
        }
        ksort($keys);
        foreach ($keys as $key => $outcomeId) {
            $outcome = $this->freshEntities->findFreshLockedCurriculumLearningOutcome(
                $outcomeId,
                LockMode::NONE,
            );
            if (!$outcome instanceof CurriculumLearningOutcome) {
                throw QuestionException::alignmentInvalid('Learning outcome not found.');
            }
            $lockedOutcomes[$key] = $outcome;
        }

        return $lockedOutcomes;
    }

    private function mapDriverException(\Throwable $throwable): never
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof DriverException && '45000' === $current->getSQLState()) {
                throw QuestionException::immutable();
            }
            if ($current instanceof \Doctrine\DBAL\Driver\Exception && '45000' === $current->getSQLState()) {
                throw QuestionException::immutable();
            }
        }

        throw $throwable;
    }

    /**
     * @param array{
     *     id: string,
     *     scope: string,
     *     institution_id: ?string,
     *     subject_id: string,
     *     grade_level: int,
     *     status: string,
     *     current_revision_number: int,
     *     created_by_id: string
     * } $snapshot
     */
    private function assertQuestionSnapshotUnchanged(Question $question, array $snapshot, bool $full): void
    {
        if (!$question->getId()->equals(Uuid::fromString($snapshot['id']))) {
            throw QuestionException::conflict();
        }
        if ($question->getScope()->value !== $snapshot['scope']) {
            throw QuestionException::conflict();
        }
        $institutionId = $question->getInstitution()?->getId()?->toRfc4122();
        if ($institutionId !== $snapshot['institution_id']) {
            throw QuestionException::conflict();
        }
        if (!$question->getSubject()->getId()->equals(Uuid::fromString($snapshot['subject_id']))) {
            throw QuestionException::conflict();
        }
        if (!$full) {
            return;
        }
        if ($question->getGradeLevel()->value !== $snapshot['grade_level']) {
            throw QuestionException::conflict();
        }
        if ($question->getStatus()->value !== $snapshot['status']) {
            throw QuestionException::conflict();
        }
        if ($question->getCurrentRevisionNumber() !== $snapshot['current_revision_number']) {
            throw QuestionException::conflict();
        }
        if (!$question->getCreatedBy()->getId()->equals(Uuid::fromString($snapshot['created_by_id']))) {
            throw QuestionException::conflict();
        }
    }

    private function fetchRevisionIdForQuestionNumber(Uuid $questionId, int $revisionNumber): ?Uuid
    {
        $raw = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM question_revisions WHERE question_id = ? AND revision_number = ?',
            [$questionId->toBinary(), $revisionNumber],
            [ParameterType::BINARY, ParameterType::INTEGER],
        );
        if (false === $raw || null === $raw) {
            return null;
        }

        return $this->uuidFromDb($raw);
    }

    private function findFreshAnswerKeyForRevision(QuestionRevision $revision): ?QuestionAnswerKey
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('k')
            ->from(QuestionAnswerKey::class, 'k')
            ->where('k.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);

        $result = $query->getOneOrNullResult();

        return $result instanceof QuestionAnswerKey ? $result : null;
    }

    /**
     * @return list<QuestionRevisionOption>
     */
    private function findFreshOptionsForRevision(QuestionRevision $revision): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(QuestionRevisionOption::class, 'o')
            ->where('o.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->orderBy('o.position', 'ASC')
            ->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);

        /** @var list<QuestionRevisionOption> $rows */
        $rows = $query->getResult();

        return $rows;
    }

    /**
     * @return list<QuestionRevisionAlignment>
     */
    private function findFreshAlignmentsForRevision(QuestionRevision $revision): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(QuestionRevisionAlignment::class, 'a')
            ->where('a.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);

        /** @var list<QuestionRevisionAlignment> $rows */
        $rows = $query->getResult();

        return $rows;
    }

    private function uuidFromDb(mixed $value): Uuid
    {
        if ($value instanceof Uuid) {
            return $value;
        }
        if (!\is_string($value) || '' === $value) {
            throw QuestionException::conflict();
        }
        if (16 === \strlen($value)) {
            return Uuid::fromBinary($value);
        }

        return Uuid::fromString($value);
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

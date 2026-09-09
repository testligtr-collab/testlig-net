<?php

declare(strict_types=1);

namespace App\Service;

use App\Assessment\AssessmentContentPolicy;
use App\Assessment\AssessmentManifestBuilder;
use App\Assessment\AssessmentManifestHasher;
use App\Assessment\AssessmentPublicationIntegrityVerifier;
use App\Assessment\AssessmentPublicContentHashBuilder;
use App\Assessment\AssessmentRevisionPublicHashBuilder;
use App\Assessment\AssessmentScore;
use App\Dto\SecurityAuditContext;
use App\Entity\Assessment;
use App\Entity\AssessmentItem;
use App\Entity\AssessmentPublication;
use App\Entity\AssessmentRevision;
use App\Entity\AssessmentSection;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\Question;
use App\Entity\QuestionAnswerKey;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionStatus;
use App\Enum\ResultReleasePolicy;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Exception\AssessmentException;
use App\Question\Answer\QuestionAnswerIntegrityHasher;
use App\Repository\AssessmentItemRepository;
use App\Repository\AssessmentPublicationRepository;
use App\Repository\AssessmentRepository;
use App\Repository\AssessmentRevisionRepository;
use App\Repository\AssessmentSectionRepository;
use App\Repository\QuestionAnswerKeyRepository;
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
 * Assessment blueprint lifecycle. Content is append-only via sealed immutable revisions.
 *
 * Global lock order (aligned with QuestionManager shared resources):
 * 1. Locksless assessment scope snapshot (when assessment exists)
 * 2. Institution? (if institution scope)
 * 3. Assessment PESSIMISTIC_WRITE + HINT_REFRESH; revalidate snapshot
 * 4. Subjects UUID ascending (from items' questions)
 * 5. Questions UUID ascending
 * 6. QuestionRevisions UUID ascending
 * 7. Users UUID ascending
 * 8. Persist AssessmentRevision (is_sealed=false) + sections + items
 * 9. assignCurrentRevision; seal revision (is_sealed 0→1)
 * 10. On publish: verify public hash → AssessmentPublication → published pointers
 *
 * Known limitation: no multi-process concurrency harness; uniqueness + pessimistic locks
 * provide sequential safety only.
 */
final class AssessmentManager
{
    public function __construct(
        private readonly AssessmentRepository $assessments,
        private readonly AssessmentRevisionRepository $revisions,
        private readonly AssessmentSectionRepository $sections,
        private readonly AssessmentItemRepository $items,
        private readonly AssessmentPublicationRepository $publications,
        private readonly QuestionAnswerKeyRepository $answerKeys,
        private readonly AssessmentContentPolicy $contentPolicy,
        private readonly AssessmentPublicContentHashBuilder $publicContentHashBuilder,
        private readonly AssessmentManifestBuilder $manifestBuilder,
        private readonly AssessmentManifestHasher $manifestHasher,
        private readonly AssessmentPublicationIntegrityVerifier $publicationIntegrityVerifier,
        private readonly QuestionAnswerIntegrityHasher $answerIntegrityHasher,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array{
     *     title: string,
     *     instructions?: string|null,
     *     position: int,
     *     durationSeconds?: int|null,
     *     questionOrderMode: QuestionOrderMode|string,
     *     items: list<array{
     *         questionId: Uuid|string,
     *         questionRevisionId: Uuid|string,
     *         position: int,
     *         points: string,
     *         penaltyPoints: string,
     *         required: bool,
     *         optionOrderMode?: OptionOrderMode|string|null
     *     }>
     * }> $sections
     */
    public function createDraftAssessment(
        User $actor,
        AssessmentScope $scope,
        ?Institution $institution,
        AssessmentType $type,
        GradeLevel $gradeLevel,
        string $title,
        ?string $description,
        ?string $instructions,
        ?int $durationSeconds,
        NavigationMode $navigationMode,
        QuestionOrderMode $questionOrderMode,
        OptionOrderMode $optionOrderMode,
        ResultReleasePolicy $resultReleasePolicy,
        ?string $passScorePercentage,
        array $sections,
        string $reasonCode,
    ): Assessment {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $normalized = $this->normalizeRevisionInput(
            $title,
            $description,
            $instructions,
            $durationSeconds,
            $navigationMode,
            $questionOrderMode,
            $optionOrderMode,
            $resultReleasePolicy,
            $passScorePercentage,
            $sections,
        );
        $actorId = $actor->getId();
        $institutionId = $institution?->getId();

        try {
            $assessment = $this->entityManager->wrapInTransaction(function () use (
                $actorId,
                $institutionId,
                $scope,
                $type,
                $gradeLevel,
                $normalized,
                $reasonCode,
            ): Assessment {
                $lockedInstitution = null;
                if (null !== $institutionId) {
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $institutionId,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution) {
                        throw AssessmentException::notFound();
                    }
                }

                $bundle = $this->lockQuestionGraphForItems($normalized['sections']);
                $userIds = $this->uniqueSortedIds([$actorId]);
                $users = $this->freshEntities->findFreshLockedUsers($userIds, LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AssessmentException::userNotFound();
                }
                $this->assertActorMayCreate($freshActor, $scope, $lockedInstitution);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $assessment = Assessment::createDraft(
                    $scope,
                    $lockedInstitution,
                    $type,
                    $gradeLevel,
                    $freshActor,
                    $now,
                );
                $this->assessments->save($assessment, false);

                $revision = $this->persistRevisionBundle(
                    $assessment,
                    1,
                    $freshActor,
                    $normalized,
                    $bundle,
                    $now,
                );

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'assessment_manager',
                        'reason_code' => $reasonCode,
                        'assessment_id' => $assessment->getId()->toRfc4122(),
                        'assessment_revision_id' => $revision->getId()->toRfc4122(),
                        'revision_number' => 1,
                        'assessment_type' => $type->value,
                        'grade_level' => $gradeLevel->value,
                        'institution_id' => $lockedInstitution?->getId()->toRfc4122(),
                        'section_count' => \count($normalized['sections']),
                        'item_count' => $this->countItems($normalized['sections']),
                        'new_status' => $assessment->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $assessment;
            });
        } catch (AssessmentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateAssessment($assessment->getId());

        return $assessment;
    }

    /**
     * @param list<array{
     *     title: string,
     *     instructions?: string|null,
     *     position: int,
     *     durationSeconds?: int|null,
     *     questionOrderMode: QuestionOrderMode|string,
     *     items: list<array{
     *         questionId: Uuid|string,
     *         questionRevisionId: Uuid|string,
     *         position: int,
     *         points: string,
     *         penaltyPoints: string,
     *         required: bool,
     *         optionOrderMode?: OptionOrderMode|string|null
     *     }>
     * }> $sections
     */
    public function createRevision(
        Assessment $assessment,
        User $actor,
        string $title,
        ?string $description,
        ?string $instructions,
        ?int $durationSeconds,
        NavigationMode $navigationMode,
        QuestionOrderMode $questionOrderMode,
        OptionOrderMode $optionOrderMode,
        ResultReleasePolicy $resultReleasePolicy,
        ?string $passScorePercentage,
        array $sections,
        string $reasonCode,
    ): AssessmentRevision {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $normalized = $this->normalizeRevisionInput(
            $title,
            $description,
            $instructions,
            $durationSeconds,
            $navigationMode,
            $questionOrderMode,
            $optionOrderMode,
            $resultReleasePolicy,
            $passScorePercentage,
            $sections,
        );
        $assessmentId = $assessment->getId();
        $actorId = $actor->getId();

        try {
            $revision = $this->entityManager->wrapInTransaction(function () use (
                $assessmentId,
                $actorId,
                $normalized,
                $reasonCode,
            ): AssessmentRevision {
                $snapshot = $this->fetchAssessmentScopeSnapshot($assessmentId);
                if (null === $snapshot) {
                    throw AssessmentException::notFound();
                }

                $lockedInstitution = null;
                if (AssessmentScope::Institution->value === $snapshot['scope']) {
                    if (null === $snapshot['institution_id']) {
                        throw AssessmentException::scopeMismatch();
                    }
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        Uuid::fromString($snapshot['institution_id']),
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution) {
                        throw AssessmentException::notFound();
                    }
                }

                $lockedAssessment = $this->freshEntities->findFreshLockedAssessment(
                    $assessmentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedAssessment instanceof Assessment) {
                    throw AssessmentException::notFound();
                }
                $this->assertAssessmentSnapshotUnchanged($lockedAssessment, $snapshot);

                $bundle = $this->lockQuestionGraphForItems($normalized['sections']);
                $userIds = $this->uniqueSortedIds([$actorId, $lockedAssessment->getCreatedBy()->getId()]);
                $users = $this->freshEntities->findFreshLockedUsers($userIds, LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AssessmentException::userNotFound();
                }
                $this->assertActorMayRevise($freshActor, $lockedAssessment);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $lockedAssessment->prepareForNewRevision($now);
                $revisionNumber = ($lockedAssessment->getCurrentRevisionNumber() ?? 0) + 1;
                $this->assessments->save($lockedAssessment, false);

                $revision = $this->persistRevisionBundle(
                    $lockedAssessment,
                    $revisionNumber,
                    $freshActor,
                    $normalized,
                    $bundle,
                    $now,
                );

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentRevisionCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'assessment_manager',
                        'reason_code' => $reasonCode,
                        'assessment_id' => $lockedAssessment->getId()->toRfc4122(),
                        'assessment_revision_id' => $revision->getId()->toRfc4122(),
                        'revision_number' => $revisionNumber,
                        'assessment_type' => $lockedAssessment->getType()->value,
                        'grade_level' => $lockedAssessment->getGradeLevel()->value,
                        'institution_id' => $lockedInstitution?->getId()->toRfc4122(),
                        'section_count' => \count($normalized['sections']),
                        'item_count' => $this->countItems($normalized['sections']),
                        'old_status' => $snapshot['status'],
                        'new_status' => $lockedAssessment->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $revision;
            });
        } catch (AssessmentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateAssessment($assessmentId);

        return $revision;
    }

    public function submitForReview(Assessment $assessment, User $actor, string $reasonCode): void
    {
        $this->transition(
            $assessment,
            $actor,
            $reasonCode,
            SecurityAuditAction::AssessmentSubmittedForReview,
            static function (Assessment $a, \DateTimeImmutable $now): void {
                $a->submitForReview($now);
            },
            requireRevise: true,
        );
    }

    public function returnToDraft(Assessment $assessment, User $actor, string $reasonCode): void
    {
        $this->transition(
            $assessment,
            $actor,
            $reasonCode,
            SecurityAuditAction::AssessmentReturnedToDraft,
            static function (Assessment $a, \DateTimeImmutable $now): void {
                $a->returnToDraft($now);
            },
            requireReview: true,
        );
    }

    public function archive(Assessment $assessment, User $actor, string $reasonCode): void
    {
        $this->transition(
            $assessment,
            $actor,
            $reasonCode,
            SecurityAuditAction::AssessmentArchived,
            static function (Assessment $a, \DateTimeImmutable $now): void {
                $a->archive($now);
            },
            requireArchive: true,
        );
    }

    public function publish(Assessment $assessment, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $assessmentId = $assessment->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use ($assessmentId, $actorId, $reasonCode): void {
                $snapshot = $this->fetchAssessmentScopeSnapshot($assessmentId);
                if (null === $snapshot) {
                    throw AssessmentException::notFound();
                }

                if (AssessmentScope::Institution->value === $snapshot['scope']) {
                    if (null === $snapshot['institution_id']) {
                        throw AssessmentException::scopeMismatch();
                    }
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        Uuid::fromString($snapshot['institution_id']),
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution) {
                        throw AssessmentException::notFound();
                    }
                }

                $lockedAssessment = $this->freshEntities->findFreshLockedAssessment(
                    $assessmentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedAssessment instanceof Assessment) {
                    throw AssessmentException::notFound();
                }
                $this->assertAssessmentSnapshotUnchanged($lockedAssessment, $snapshot, full: true);

                $currentRevision = $lockedAssessment->getCurrentRevision();
                if (!$currentRevision instanceof AssessmentRevision) {
                    throw AssessmentException::notFound();
                }
                $revision = $this->freshEntities->findFreshLockedAssessmentRevision(
                    $currentRevision->getId(),
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$revision instanceof AssessmentRevision) {
                    throw AssessmentException::notFound();
                }
                if (!$revision->getAssessment()->getId()->equals($lockedAssessment->getId())) {
                    throw AssessmentException::conflict();
                }
                if (!$revision->isSealed()) {
                    throw AssessmentException::revisionNotSealed();
                }
                if ($lockedAssessment->getCurrentRevisionNumber() !== $revision->getRevisionNumber()) {
                    throw AssessmentException::conflict();
                }

                $graph = $this->loadFreshRevisionGraph($revision);
                $this->assertPublishableGraph($lockedAssessment, $revision, $graph);
                $this->assertPublicContentHashMatches($revision, $graph);

                $userIds = $this->uniqueSortedIds([
                    $actorId,
                    $revision->getCreatedBy()->getId(),
                    $lockedAssessment->getCreatedBy()->getId(),
                ]);
                $users = $this->freshEntities->findFreshLockedUsers($userIds, LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw AssessmentException::userNotFound();
                }
                $this->assertActorMayPublish($freshActor, $lockedAssessment);

                $revisionAuthor = $users[$revision->getCreatedBy()->getId()->toRfc4122()] ?? null;
                if (!$revisionAuthor instanceof User) {
                    throw AssessmentException::userNotFound();
                }
                if ($freshActor->getId()->equals($revisionAuthor->getId())) {
                    throw AssessmentException::reviewSeparation();
                }

                $manifest = $this->buildManifest($lockedAssessment, $revision, $graph);
                $manifestHash = $this->manifestHasher->hash($manifest);

                $publicationNumber = $this->nextPublicationNumber($assessmentId);
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $oldStatus = $lockedAssessment->getStatus()->value;

                // Publication BEFORE published pointers (MariaDB published-requires-publication trigger).
                $publication = AssessmentPublication::create(
                    $lockedAssessment,
                    $revision,
                    $publicationNumber,
                    $manifest,
                    $manifestHash,
                    $freshActor,
                    AssessmentManifestBuilder::SCHEMA_VERSION,
                    $now,
                );
                $this->publications->save($publication, false);
                $this->entityManager->flush();

                $this->publicationIntegrityVerifier->verify($publication, $lockedAssessment, $revision);

                $lockedAssessment->publish($revision, $now);
                $this->assessments->save($lockedAssessment, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentPublicationCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'assessment_manager',
                        'reason_code' => $reasonCode,
                        'assessment_id' => $lockedAssessment->getId()->toRfc4122(),
                        'assessment_revision_id' => $revision->getId()->toRfc4122(),
                        'revision_number' => $revision->getRevisionNumber(),
                        'publication_id' => $publication->getId()->toRfc4122(),
                        'publication_number' => $publicationNumber,
                        'section_count' => \count($graph['sections']),
                        'item_count' => \count($graph['items']),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::AssessmentPublished,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'assessment_manager',
                        'reason_code' => $reasonCode,
                        'assessment_id' => $lockedAssessment->getId()->toRfc4122(),
                        'assessment_revision_id' => $revision->getId()->toRfc4122(),
                        'revision_number' => $revision->getRevisionNumber(),
                        'publication_id' => $publication->getId()->toRfc4122(),
                        'publication_number' => $publicationNumber,
                        'old_status' => $oldStatus,
                        'new_status' => $lockedAssessment->getStatus()->value,
                        'assessment_type' => $lockedAssessment->getType()->value,
                        'grade_level' => $lockedAssessment->getGradeLevel()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (AssessmentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateAssessment($assessmentId);
    }

    /**
     * @param callable(Assessment, \DateTimeImmutable): void $mutator
     */
    private function transition(
        Assessment $assessment,
        User $actor,
        string $reasonCode,
        SecurityAuditAction $action,
        callable $mutator,
        bool $requireRevise = false,
        bool $requireReview = false,
        bool $requireArchive = false,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $assessmentId = $assessment->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $assessmentId,
                $actorId,
                $reasonCode,
                $action,
                $mutator,
                $requireRevise,
                $requireReview,
                $requireArchive,
            ): void {
                $snapshot = $this->fetchAssessmentScopeSnapshot($assessmentId);
                if (null === $snapshot) {
                    throw AssessmentException::notFound();
                }

                if (AssessmentScope::Institution->value === $snapshot['scope']) {
                    if (null === $snapshot['institution_id']) {
                        throw AssessmentException::scopeMismatch();
                    }
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        Uuid::fromString($snapshot['institution_id']),
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution) {
                        throw AssessmentException::notFound();
                    }
                }

                $lockedAssessment = $this->freshEntities->findFreshLockedAssessment(
                    $assessmentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedAssessment instanceof Assessment) {
                    throw AssessmentException::notFound();
                }
                $this->assertAssessmentSnapshotUnchanged($lockedAssessment, $snapshot, full: true);

                $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                if (!$freshActor instanceof User) {
                    throw AssessmentException::userNotFound();
                }

                if ($requireReview || $requireArchive) {
                    $this->assertActorMayReview($freshActor, $lockedAssessment);
                } elseif ($requireRevise) {
                    $this->assertActorMayRevise($freshActor, $lockedAssessment);
                } else {
                    throw AssessmentException::unauthorized();
                }

                $oldStatus = $lockedAssessment->getStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $mutator($lockedAssessment, $now);
                $this->assessments->save($lockedAssessment, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $action,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'assessment_manager',
                        'reason_code' => $reasonCode,
                        'assessment_id' => $lockedAssessment->getId()->toRfc4122(),
                        'old_status' => $oldStatus,
                        'new_status' => $lockedAssessment->getStatus()->value,
                        'assessment_type' => $lockedAssessment->getType()->value,
                        'grade_level' => $lockedAssessment->getGradeLevel()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (AssessmentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw AssessmentException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateAssessment($assessmentId);
    }

    /**
     * @param array{
     *     title: string,
     *     description: ?string,
     *     instructions: ?string,
     *     durationSeconds: ?int,
     *     navigationMode: NavigationMode,
     *     questionOrderMode: QuestionOrderMode,
     *     optionOrderMode: OptionOrderMode,
     *     resultReleasePolicy: ResultReleasePolicy,
     *     passScorePercentage: ?string,
     *     sections: list<array{
     *         title: string,
     *         instructions: ?string,
     *         position: int,
     *         durationSeconds: ?int,
     *         questionOrderMode: QuestionOrderMode,
     *         items: list<array{
     *             questionId: Uuid,
     *             questionRevisionId: Uuid,
     *             position: int,
     *             points: string,
     *             penaltyPoints: string,
     *             required: bool,
     *             optionOrderMode: ?OptionOrderMode
     *         }>
     *     }>
     * } $normalized
     * @param array{
     *     subjects: array<string, Subject>,
     *     questions: array<string, Question>,
     *     revisions: array<string, QuestionRevision>
     * } $bundle
     */
    private function persistRevisionBundle(
        Assessment $assessment,
        int $revisionNumber,
        User $actor,
        array $normalized,
        array $bundle,
        \DateTimeImmutable $now,
    ): AssessmentRevision {
        $this->assertItemsCompatibleWithAssessment($assessment, $normalized['sections'], $bundle);

        $publicSections = [];
        foreach ($normalized['sections'] as $sectionSpec) {
            $publicItems = [];
            foreach ($sectionSpec['items'] as $itemSpec) {
                $question = $bundle['questions'][$itemSpec['questionId']->toRfc4122()];
                $qRevision = $bundle['revisions'][$itemSpec['questionRevisionId']->toRfc4122()];
                $publicItems[] = [
                    'position' => $itemSpec['position'],
                    'questionId' => $question->getId()->toRfc4122(),
                    'questionRevisionId' => $qRevision->getId()->toRfc4122(),
                    'questionRevisionNumber' => $qRevision->getRevisionNumber(),
                    'questionPublicContentHash' => $qRevision->getContentHash(),
                    'subjectId' => $question->getSubject()->getId()->toRfc4122(),
                    'points' => $itemSpec['points'],
                    'penaltyPoints' => $itemSpec['penaltyPoints'],
                    'required' => $itemSpec['required'],
                    'optionOrderMode' => $itemSpec['optionOrderMode']?->value,
                ];
            }
            $publicSections[] = [
                'position' => $sectionSpec['position'],
                'title' => $sectionSpec['title'],
                'instructions' => $sectionSpec['instructions'],
                'durationSeconds' => $sectionSpec['durationSeconds'],
                'questionOrderMode' => $sectionSpec['questionOrderMode']->value,
                'items' => $publicItems,
            ];
        }

        $publicHash = $this->publicContentHashBuilder->hash(
            $normalized['title'],
            $normalized['description'],
            $normalized['instructions'],
            $normalized['durationSeconds'],
            $normalized['navigationMode']->value,
            $normalized['questionOrderMode']->value,
            $normalized['optionOrderMode']->value,
            $normalized['resultReleasePolicy']->value,
            $normalized['passScorePercentage'],
            $publicSections,
            AssessmentRevisionPublicHashBuilder::SCHEMA_VERSION,
        );

        $revision = AssessmentRevision::create(
            $assessment,
            $revisionNumber,
            $normalized['title'],
            $normalized['description'],
            $normalized['instructions'],
            $normalized['durationSeconds'],
            $normalized['navigationMode'],
            $normalized['questionOrderMode'],
            $normalized['optionOrderMode'],
            $normalized['resultReleasePolicy'],
            $normalized['passScorePercentage'],
            $actor,
            $publicHash,
            AssessmentRevisionPublicHashBuilder::SCHEMA_VERSION,
            $now,
        );
        $this->revisions->save($revision, false);

        foreach ($normalized['sections'] as $sectionSpec) {
            $section = AssessmentSection::create(
                $revision,
                $sectionSpec['title'],
                $sectionSpec['instructions'],
                $sectionSpec['position'],
                $sectionSpec['durationSeconds'],
                $sectionSpec['questionOrderMode'],
                $now,
            );
            $this->sections->save($section, false);

            foreach ($sectionSpec['items'] as $itemSpec) {
                $question = $bundle['questions'][$itemSpec['questionId']->toRfc4122()];
                $qRevision = $bundle['revisions'][$itemSpec['questionRevisionId']->toRfc4122()];
                $item = AssessmentItem::create(
                    $section,
                    $revision,
                    $question,
                    $qRevision,
                    $itemSpec['position'],
                    $itemSpec['points'],
                    $itemSpec['penaltyPoints'],
                    $itemSpec['required'],
                    $itemSpec['optionOrderMode'],
                    $now,
                );
                $this->items->save($item, false);
            }
        }

        // Flush unsealed graph first so MariaDB sees is_sealed=0 on INSERT (Doctrine would
        // otherwise INSERT the post-seal state and BI triggers would reject sections/items).
        $this->entityManager->flush();

        $assessment->assignCurrentRevision($revision, $now);
        $this->assessments->save($assessment, false);

        $revision->seal();
        $this->revisions->save($revision, false);
        $this->entityManager->flush();

        return $revision;
    }

    /**
     * @param list<array{
     *     title: string,
     *     instructions: ?string,
     *     position: int,
     *     durationSeconds: ?int,
     *     questionOrderMode: QuestionOrderMode,
     *     items: list<array{
     *         questionId: Uuid,
     *         questionRevisionId: Uuid,
     *         position: int,
     *         points: string,
     *         penaltyPoints: string,
     *         required: bool,
     *         optionOrderMode: ?OptionOrderMode
     *     }>
     * }> $sections
     * @param array{
     *     subjects: array<string, Subject>,
     *     questions: array<string, Question>,
     *     revisions: array<string, QuestionRevision>
     * } $bundle
     */
    private function assertItemsCompatibleWithAssessment(Assessment $assessment, array $sections, array $bundle): void
    {
        $seenQuestionRevisions = [];
        foreach ($sections as $sectionSpec) {
            foreach ($sectionSpec['items'] as $itemSpec) {
                $qKey = $itemSpec['questionRevisionId']->toRfc4122();
                if (isset($seenQuestionRevisions[$qKey])) {
                    throw AssessmentException::duplicateQuestion();
                }
                $seenQuestionRevisions[$qKey] = true;

                $question = $bundle['questions'][$itemSpec['questionId']->toRfc4122()] ?? null;
                $qRevision = $bundle['revisions'][$qKey] ?? null;
                if (!$question instanceof Question || !$qRevision instanceof QuestionRevision) {
                    throw AssessmentException::notFound();
                }
                if (!$qRevision->getQuestion()->getId()->equals($question->getId())) {
                    throw AssessmentException::questionRevisionMismatch();
                }
                if (QuestionStatus::Published !== $question->getStatus()) {
                    throw AssessmentException::questionNotPublished();
                }
                if ($qRevision->getRevisionNumber() !== $question->getCurrentRevisionNumber()) {
                    throw AssessmentException::questionRevisionMismatch();
                }
                if ($question->getGradeLevel() !== $assessment->getGradeLevel()) {
                    throw AssessmentException::gradeMismatch();
                }
                $this->assertQuestionScopeAllowed($assessment, $question);
            }
        }
    }

    private function assertQuestionScopeAllowed(Assessment $assessment, Question $question): void
    {
        if (AssessmentScope::Platform === $assessment->getScope()) {
            if (QuestionScope::Platform !== $question->getScope()) {
                throw AssessmentException::questionScopeMismatch();
            }

            return;
        }

        if (QuestionScope::Platform === $question->getScope()) {
            return;
        }
        $assessmentInstitution = $assessment->getInstitution();
        $questionInstitution = $question->getInstitution();
        if (!$assessmentInstitution instanceof Institution
            || !$questionInstitution instanceof Institution
            || !$assessmentInstitution->getId()->equals($questionInstitution->getId())) {
            throw AssessmentException::questionScopeMismatch();
        }
    }

    /**
     * @param list<array{
     *     title: string,
     *     instructions: ?string,
     *     position: int,
     *     durationSeconds: ?int,
     *     questionOrderMode: QuestionOrderMode,
     *     items: list<array{
     *         questionId: Uuid,
     *         questionRevisionId: Uuid,
     *         position: int,
     *         points: string,
     *         penaltyPoints: string,
     *         required: bool,
     *         optionOrderMode: ?OptionOrderMode
     *     }>
     * }> $sections
     *
     * @return array{
     *     subjects: array<string, Subject>,
     *     questions: array<string, Question>,
     *     revisions: array<string, QuestionRevision>
     * }
     */
    private function lockQuestionGraphForItems(array $sections): array
    {
        $questionIds = [];
        $revisionIds = [];
        foreach ($sections as $sectionSpec) {
            foreach ($sectionSpec['items'] as $itemSpec) {
                $questionIds[$itemSpec['questionId']->toRfc4122()] = $itemSpec['questionId'];
                $revisionIds[$itemSpec['questionRevisionId']->toRfc4122()] = $itemSpec['questionRevisionId'];
            }
        }
        ksort($questionIds);
        ksort($revisionIds);

        // Locksless subject discovery keeps Subject → Question lock order (QuestionManager-aligned).
        $subjectIds = [];
        foreach ($questionIds as $questionId) {
            $subjectRaw = $this->entityManager->getConnection()->fetchOne(
                'SELECT subject_id FROM questions WHERE id = ?',
                [$questionId->toBinary()],
                [ParameterType::BINARY],
            );
            if (false === $subjectRaw || null === $subjectRaw) {
                throw AssessmentException::notFound();
            }
            $subjectId = $this->uuidFromDb($subjectRaw);
            $subjectIds[$subjectId->toRfc4122()] = $subjectId;
        }
        ksort($subjectIds);

        $subjects = [];
        foreach ($subjectIds as $key => $subjectId) {
            $subject = $this->freshEntities->findFreshLockedSubject($subjectId, LockMode::PESSIMISTIC_WRITE);
            if (!$subject instanceof Subject) {
                throw AssessmentException::notFound();
            }
            $subjects[$key] = $subject;
        }

        $questions = [];
        foreach ($questionIds as $key => $questionId) {
            $question = $this->freshEntities->findFreshLockedQuestion($questionId, LockMode::PESSIMISTIC_WRITE);
            if (!$question instanceof Question) {
                throw AssessmentException::notFound();
            }
            $questions[$key] = $question;
        }

        $revisions = [];
        foreach ($revisionIds as $key => $revisionId) {
            $revision = $this->freshEntities->findFreshLockedQuestionRevision($revisionId, LockMode::PESSIMISTIC_WRITE);
            if (!$revision instanceof QuestionRevision) {
                throw AssessmentException::notFound();
            }
            $revisions[$key] = $revision;
        }

        return [
            'subjects' => $subjects,
            'questions' => $questions,
            'revisions' => $revisions,
        ];
    }

    /**
     * @return array{
     *     sections: list<AssessmentSection>,
     *     items: list<AssessmentItem>
     * }
     */
    private function loadFreshRevisionGraph(AssessmentRevision $revision): array
    {
        /** @var list<AssessmentSection> $sections */
        $sections = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(AssessmentSection::class, 's')
            ->andWhere('s.revision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->orderBy('s.position', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        /** @var list<AssessmentItem> $items */
        $items = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(AssessmentItem::class, 'i')
            ->andWhere('i.assessmentRevision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->orderBy('i.position', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return ['sections' => $sections, 'items' => $items];
    }

    /**
     * @param array{sections: list<AssessmentSection>, items: list<AssessmentItem>} $graph
     */
    private function assertPublicContentHashMatches(AssessmentRevision $revision, array $graph): void
    {
        $stored = $revision->getPublicContentHash();
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $stored)) {
            throw AssessmentException::publicContentIntegrityFailed();
        }

        $recomputed = $this->publicContentHashBuilder->hashFromGraph(
            $revision,
            $graph['sections'],
            $graph['items'],
        );
        if (!hash_equals($stored, $recomputed)) {
            throw AssessmentException::publicContentIntegrityFailed();
        }
    }

    /**
     * @param array{sections: list<AssessmentSection>, items: list<AssessmentItem>} $graph
     */
    private function assertPublishableGraph(Assessment $assessment, AssessmentRevision $revision, array $graph): void
    {
        if ([] === $graph['sections']) {
            throw AssessmentException::emptyAssessment();
        }

        $itemsBySection = [];
        foreach ($graph['items'] as $item) {
            $itemsBySection[$item->getSection()->getId()->toRfc4122()][] = $item;
        }

        $questionIds = [];
        $revisionIds = [];
        $subjectIds = [];
        foreach ($graph['items'] as $item) {
            $questionIds[$item->getQuestion()->getId()->toRfc4122()] = $item->getQuestion()->getId();
            $revisionIds[$item->getQuestionRevision()->getId()->toRfc4122()] = $item->getQuestionRevision()->getId();
            $subjectIds[$item->getQuestion()->getSubject()->getId()->toRfc4122()] = $item->getQuestion()->getSubject()->getId();
        }
        ksort($subjectIds);
        ksort($questionIds);
        ksort($revisionIds);

        foreach ($subjectIds as $subjectId) {
            $subject = $this->freshEntities->findFreshLockedSubject($subjectId, LockMode::PESSIMISTIC_WRITE);
            if (!$subject instanceof Subject) {
                throw AssessmentException::notFound();
            }
        }

        $questions = [];
        foreach ($questionIds as $key => $questionId) {
            $question = $this->freshEntities->findFreshLockedQuestion($questionId, LockMode::PESSIMISTIC_WRITE);
            if (!$question instanceof Question) {
                throw AssessmentException::notFound();
            }
            $questions[$key] = $question;
        }

        $qRevisions = [];
        foreach ($revisionIds as $key => $revisionId) {
            $qRevision = $this->freshEntities->findFreshLockedQuestionRevision($revisionId, LockMode::PESSIMISTIC_WRITE);
            if (!$qRevision instanceof QuestionRevision) {
                throw AssessmentException::notFound();
            }
            $qRevisions[$key] = $qRevision;
        }

        foreach ($graph['sections'] as $section) {
            if (!$section->getRevision()->getId()->equals($revision->getId())) {
                throw AssessmentException::conflict();
            }
            $sectionItems = $itemsBySection[$section->getId()->toRfc4122()] ?? [];
            if ([] === $sectionItems) {
                throw AssessmentException::emptySection();
            }
        }

        $seen = [];
        foreach ($graph['items'] as $item) {
            if (!$item->getAssessmentRevision()->getId()->equals($revision->getId())) {
                throw AssessmentException::conflict();
            }
            $qRevKey = $item->getQuestionRevision()->getId()->toRfc4122();
            if (isset($seen[$qRevKey])) {
                throw AssessmentException::duplicateQuestion();
            }
            $seen[$qRevKey] = true;

            $question = $questions[$item->getQuestion()->getId()->toRfc4122()];
            $qRevision = $qRevisions[$qRevKey];
            if (!$qRevision->getQuestion()->getId()->equals($question->getId())) {
                throw AssessmentException::questionRevisionMismatch();
            }
            if (QuestionStatus::Published !== $question->getStatus()) {
                throw AssessmentException::questionNotPublished();
            }
            if ($qRevision->getRevisionNumber() !== $question->getCurrentRevisionNumber()) {
                throw AssessmentException::questionRevisionMismatch();
            }
            if ($question->getGradeLevel() !== $assessment->getGradeLevel()) {
                throw AssessmentException::gradeMismatch();
            }
            $this->assertQuestionScopeAllowed($assessment, $question);

            $answerKey = $this->answerKeys->findOneByRevision($qRevision);
            if (!$answerKey instanceof QuestionAnswerKey) {
                throw AssessmentException::answerIntegrityFailed();
            }
            $this->answerIntegrityHasher->verify(
                $answerKey->getAnswerIntegrityHmac(),
                $answerKey->getAnswerPayload(),
                $answerKey->getAnswerType(),
                $qRevision->getId(),
            );
            if ('' === $qRevision->getContentHash() || 64 !== \strlen($qRevision->getContentHash())) {
                throw AssessmentException::publicationInvalid('Question public content hash is invalid.');
            }
        }
    }

    /**
     * @param array{sections: list<AssessmentSection>, items: list<AssessmentItem>} $graph
     *
     * @return array<string, mixed>
     */
    private function buildManifest(Assessment $assessment, AssessmentRevision $revision, array $graph): array
    {
        $itemsBySection = [];
        foreach ($graph['items'] as $item) {
            $itemsBySection[$item->getSection()->getId()->toRfc4122()][] = $item;
        }

        $sections = [];
        foreach ($graph['sections'] as $section) {
            $manifestItems = [];
            foreach ($itemsBySection[$section->getId()->toRfc4122()] ?? [] as $item) {
                $manifestItems[] = [
                    'id' => $item->getId()->toRfc4122(),
                    'position' => $item->getPosition(),
                    'questionId' => $item->getQuestion()->getId()->toRfc4122(),
                    'questionRevisionId' => $item->getQuestionRevision()->getId()->toRfc4122(),
                    'questionRevisionNumber' => $item->getQuestionRevision()->getRevisionNumber(),
                    'questionPublicContentHash' => $item->getQuestionRevision()->getContentHash(),
                    'subjectId' => $item->getQuestion()->getSubject()->getId()->toRfc4122(),
                    'points' => $item->getPoints(),
                    'penaltyPoints' => $item->getPenaltyPoints(),
                    'required' => $item->isRequired(),
                    'optionOrderMode' => $item->getOptionOrderMode()?->value,
                    'questionSchemaVersion' => $item->getQuestionRevision()->getSchemaVersion(),
                ];
            }
            $sections[] = [
                'id' => $section->getId()->toRfc4122(),
                'position' => $section->getPosition(),
                'title' => $section->getTitle(),
                'instructions' => $section->getInstructions(),
                'durationSeconds' => $section->getDurationSeconds(),
                'questionOrderMode' => $section->getQuestionOrderMode()->value,
                'items' => $manifestItems,
            ];
        }

        return $this->manifestBuilder->build(
            $assessment->getId()->toRfc4122(),
            $revision->getId()->toRfc4122(),
            $revision->getRevisionNumber(),
            $assessment->getType()->value,
            $assessment->getGradeLevel()->value,
            $revision->getTitle(),
            $revision->getDescription(),
            $revision->getInstructions(),
            $revision->getDurationSeconds(),
            $revision->getNavigationMode()->value,
            $revision->getQuestionOrderMode()->value,
            $revision->getOptionOrderMode()->value,
            $revision->getResultReleasePolicy()->value,
            $revision->getPassScorePercentage(),
            $sections,
            $revision->getSchemaVersion(),
        );
    }

    /**
     * @param list<mixed> $rawSections
     *
     * @return array{
     *     title: string,
     *     description: ?string,
     *     instructions: ?string,
     *     durationSeconds: ?int,
     *     navigationMode: NavigationMode,
     *     questionOrderMode: QuestionOrderMode,
     *     optionOrderMode: OptionOrderMode,
     *     resultReleasePolicy: ResultReleasePolicy,
     *     passScorePercentage: ?string,
     *     sections: list<array{
     *         title: string,
     *         instructions: ?string,
     *         position: int,
     *         durationSeconds: ?int,
     *         questionOrderMode: QuestionOrderMode,
     *         items: list<array{
     *             questionId: Uuid,
     *             questionRevisionId: Uuid,
     *             position: int,
     *             points: string,
     *             penaltyPoints: string,
     *             required: bool,
     *             optionOrderMode: ?OptionOrderMode
     *         }>
     *     }>
     * }
     */
    private function normalizeRevisionInput(
        string $title,
        ?string $description,
        ?string $instructions,
        ?int $durationSeconds,
        NavigationMode $navigationMode,
        QuestionOrderMode $questionOrderMode,
        OptionOrderMode $optionOrderMode,
        ResultReleasePolicy $resultReleasePolicy,
        ?string $passScorePercentage,
        array $rawSections,
    ): array {
        if ([] === $rawSections) {
            throw AssessmentException::emptyAssessment();
        }

        $normalizedTitle = $this->contentPolicy->normalizeTitle($title);
        $normalizedDescription = $this->contentPolicy->normalizeOptionalPlainText(
            $description,
            AssessmentContentPolicy::DESCRIPTION_MAX,
            'description',
        );
        $normalizedInstructions = $this->contentPolicy->normalizeOptionalPlainText(
            $instructions,
            AssessmentContentPolicy::INSTRUCTIONS_MAX,
            'instructions',
        );
        $normalizedDuration = $this->contentPolicy->normalizeDurationSeconds($durationSeconds);
        $normalizedPass = AssessmentScore::normalizePassScorePercentage($passScorePercentage);

        $sections = [];
        $sectionPositions = [];
        $sectionDurations = [];
        foreach ($rawSections as $rawSection) {
            if (!\is_array($rawSection)) {
                throw AssessmentException::invalidInput('Invalid section payload.');
            }
            $sectionTitle = $this->contentPolicy->normalizeTitle((string) ($rawSection['title'] ?? ''));
            $sectionInstructionsRaw = $rawSection['instructions'] ?? null;
            $sectionInstructions = $this->contentPolicy->normalizeOptionalPlainText(
                null === $sectionInstructionsRaw ? null : (string) $sectionInstructionsRaw,
                AssessmentContentPolicy::INSTRUCTIONS_MAX,
                'section.instructions',
            );
            $position = (int) ($rawSection['position'] ?? 0);
            $this->contentPolicy->assertPositivePosition($position, 'section.position');
            if (isset($sectionPositions[$position])) {
                throw AssessmentException::invalidInput('Duplicate section position.');
            }
            $sectionPositions[$position] = true;
            $sectionDuration = \array_key_exists('durationSeconds', $rawSection) && null !== $rawSection['durationSeconds']
                ? $this->contentPolicy->normalizeDurationSeconds((int) $rawSection['durationSeconds'])
                : null;
            $sectionDurations[] = $sectionDuration;
            $sectionOrder = $this->normalizeQuestionOrderMode($rawSection['questionOrderMode'] ?? null);

            $rawItems = $rawSection['items'] ?? null;
            if (!\is_array($rawItems) || [] === $rawItems) {
                throw AssessmentException::emptySection();
            }

            $items = [];
            $itemPositions = [];
            foreach ($rawItems as $rawItem) {
                if (!\is_array($rawItem)) {
                    throw AssessmentException::invalidInput('Invalid item payload.');
                }
                $itemPosition = (int) ($rawItem['position'] ?? 0);
                $this->contentPolicy->assertPositivePosition($itemPosition, 'item.position');
                if (isset($itemPositions[$itemPosition])) {
                    throw AssessmentException::invalidInput('Duplicate item position.');
                }
                $itemPositions[$itemPosition] = true;
                $points = AssessmentScore::normalizePoints((string) ($rawItem['points'] ?? ''));
                $penalty = AssessmentScore::normalizePenalty((string) ($rawItem['penaltyPoints'] ?? '0'), $points);
                $items[] = [
                    'questionId' => $this->toUuid($rawItem['questionId'] ?? null),
                    'questionRevisionId' => $this->toUuid($rawItem['questionRevisionId'] ?? null),
                    'position' => $itemPosition,
                    'points' => $points,
                    'penaltyPoints' => $penalty,
                    'required' => (bool) ($rawItem['required'] ?? true),
                    'optionOrderMode' => $this->normalizeOptionalOptionOrderMode($rawItem['optionOrderMode'] ?? null),
                ];
            }

            usort($items, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

            $sections[] = [
                'title' => $sectionTitle,
                'instructions' => $sectionInstructions,
                'position' => $position,
                'durationSeconds' => $sectionDuration,
                'questionOrderMode' => $sectionOrder,
                'items' => $items,
            ];
        }

        $this->contentPolicy->assertSectionDurationsCompatible($normalizedDuration, $sectionDurations);
        usort($sections, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return [
            'title' => $normalizedTitle,
            'description' => $normalizedDescription,
            'instructions' => $normalizedInstructions,
            'durationSeconds' => $normalizedDuration,
            'navigationMode' => $navigationMode,
            'questionOrderMode' => $questionOrderMode,
            'optionOrderMode' => $optionOrderMode,
            'resultReleasePolicy' => $resultReleasePolicy,
            'passScorePercentage' => $normalizedPass,
            'sections' => $sections,
        ];
    }

    private function normalizeQuestionOrderMode(mixed $value): QuestionOrderMode
    {
        if ($value instanceof QuestionOrderMode) {
            return $value;
        }
        if (\is_string($value)) {
            return QuestionOrderMode::from($value);
        }
        throw AssessmentException::invalidInput('questionOrderMode is required.');
    }

    private function normalizeOptionalOptionOrderMode(mixed $value): ?OptionOrderMode
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if ($value instanceof OptionOrderMode) {
            return $value;
        }
        if (\is_string($value)) {
            return OptionOrderMode::from($value);
        }
        throw AssessmentException::invalidInput('optionOrderMode is invalid.');
    }

    private function toUuid(mixed $value): Uuid
    {
        if ($value instanceof Uuid) {
            return $value;
        }
        if (\is_string($value) && '' !== $value) {
            return Uuid::fromString($value);
        }
        throw AssessmentException::invalidInput('UUID value is required.');
    }

    /**
     * @param list<array{items: list<mixed>}> $sections
     */
    private function countItems(array $sections): int
    {
        $count = 0;
        foreach ($sections as $section) {
            $count += \count($section['items']);
        }

        return $count;
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<Uuid>
     */
    private function uniqueSortedIds(array $ids): array
    {
        $map = [];
        foreach ($ids as $id) {
            $map[$id->toRfc4122()] = $id;
        }
        ksort($map);

        return array_values($map);
    }

    /**
     * @return array{
     *     id: string,
     *     scope: string,
     *     institution_id: ?string,
     *     status: string,
     *     current_revision_id: ?string,
     *     current_revision_number: ?int,
     *     published_revision_id: ?string,
     *     published_revision_number: ?int,
     *     created_by_id: string,
     *     grade_level: int,
     *     type: string
     * }|null
     */
    private function fetchAssessmentScopeSnapshot(Uuid $assessmentId): ?array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT id, scope, institution_id, status,
                    current_revision_id, current_revision_number,
                    published_revision_id, published_revision_number,
                    created_by_id, grade_level, type
             FROM assessments
             WHERE id = ?',
            [$assessmentId->toBinary()],
            [ParameterType::BINARY],
        );
        if (false === $row) {
            return null;
        }

        return [
            'id' => $this->uuidStringFromBinary($row['id']),
            'scope' => (string) $row['scope'],
            'institution_id' => null !== $row['institution_id'] ? $this->uuidStringFromBinary($row['institution_id']) : null,
            'status' => (string) $row['status'],
            'current_revision_id' => null !== $row['current_revision_id']
                ? $this->uuidStringFromBinary($row['current_revision_id'])
                : null,
            'current_revision_number' => null !== $row['current_revision_number']
                ? (int) $row['current_revision_number']
                : null,
            'published_revision_id' => null !== $row['published_revision_id']
                ? $this->uuidStringFromBinary($row['published_revision_id'])
                : null,
            'published_revision_number' => null !== $row['published_revision_number']
                ? (int) $row['published_revision_number']
                : null,
            'created_by_id' => $this->uuidStringFromBinary($row['created_by_id']),
            'grade_level' => (int) $row['grade_level'],
            'type' => (string) $row['type'],
        ];
    }

    /**
     * @param array{
     *     id: string,
     *     scope: string,
     *     institution_id: ?string,
     *     status: string,
     *     current_revision_id: ?string,
     *     current_revision_number: ?int,
     *     published_revision_id: ?string,
     *     published_revision_number: ?int,
     *     created_by_id: string,
     *     grade_level: int,
     *     type: string
     * } $snapshot
     */
    private function assertAssessmentSnapshotUnchanged(Assessment $assessment, array $snapshot, bool $full = false): void
    {
        if (!$assessment->getId()->equals(Uuid::fromString($snapshot['id']))) {
            throw AssessmentException::conflict();
        }
        if ($assessment->getScope()->value !== $snapshot['scope']) {
            throw AssessmentException::conflict();
        }
        $institutionId = $assessment->getInstitution()?->getId()?->toRfc4122();
        if ($institutionId !== $snapshot['institution_id']) {
            throw AssessmentException::conflict();
        }
        if (!$full) {
            return;
        }
        if ($assessment->getStatus()->value !== $snapshot['status']) {
            throw AssessmentException::conflict();
        }
        $currentRevisionId = $assessment->getCurrentRevision()?->getId()->toRfc4122();
        if ($currentRevisionId !== $snapshot['current_revision_id']) {
            throw AssessmentException::conflict();
        }
        if ($assessment->getCurrentRevisionNumber() !== $snapshot['current_revision_number']) {
            throw AssessmentException::conflict();
        }
        $publishedRevisionId = $assessment->getPublishedRevision()?->getId()->toRfc4122();
        if ($publishedRevisionId !== $snapshot['published_revision_id']) {
            throw AssessmentException::conflict();
        }
        if ($assessment->getPublishedRevisionNumber() !== $snapshot['published_revision_number']) {
            throw AssessmentException::conflict();
        }
        if (!$assessment->getCreatedBy()->getId()->equals(Uuid::fromString($snapshot['created_by_id']))) {
            throw AssessmentException::conflict();
        }
        if ($assessment->getGradeLevel()->value !== $snapshot['grade_level']) {
            throw AssessmentException::conflict();
        }
        if ($assessment->getType()->value !== $snapshot['type']) {
            throw AssessmentException::conflict();
        }
    }

    private function nextPublicationNumber(Uuid $assessmentId): int
    {
        $max = $this->entityManager->getConnection()->fetchOne(
            'SELECT MAX(publication_number) FROM assessment_publications WHERE assessment_id = ?',
            [$assessmentId->toBinary()],
            [ParameterType::BINARY],
        );

        return null === $max || false === $max ? 1 : ((int) $max) + 1;
    }

    private function assertActorMayCreate(User $actor, AssessmentScope $scope, ?Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw AssessmentException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }

        if (AssessmentScope::Platform === $scope) {
            if ($this->hasAnyRole($actor, [UserRole::HeadTeacher, UserRole::ExpertTeacher, UserRole::Teacher])) {
                return;
            }
            throw AssessmentException::unauthorized();
        }

        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw AssessmentException::unauthorized();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()) {
            throw AssessmentException::unauthorized();
        }
        if (!\in_array($membership->getRole(), [
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
            InstitutionMembershipRole::Teacher,
        ], true)) {
            throw AssessmentException::unauthorized();
        }
    }

    private function assertActorMayRevise(User $actor, Assessment $assessment): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw AssessmentException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }

        if (AssessmentScope::Platform === $assessment->getScope()) {
            if ($this->hasAnyRole($actor, [UserRole::HeadTeacher, UserRole::ExpertTeacher])) {
                return;
            }
            if ($this->hasAnyRole($actor, [UserRole::Teacher])
                && $assessment->getCreatedBy()->getId()->equals($actor->getId())
                && AssessmentStatus::Archived !== $assessment->getStatus()) {
                return;
            }
            throw AssessmentException::unauthorized();
        }

        $institution = $assessment->getInstitution();
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw AssessmentException::unauthorized();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()) {
            throw AssessmentException::unauthorized();
        }
        if (\in_array($membership->getRole(), [InstitutionMembershipRole::Owner, InstitutionMembershipRole::Manager], true)) {
            return;
        }
        if (InstitutionMembershipRole::Teacher === $membership->getRole()
            && $assessment->getCreatedBy()->getId()->equals($actor->getId())) {
            return;
        }

        throw AssessmentException::unauthorized();
    }

    private function assertActorMayReview(User $actor, Assessment $assessment): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw AssessmentException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }
        if (AssessmentScope::Platform === $assessment->getScope()) {
            if ($this->hasAnyRole($actor, [UserRole::HeadTeacher, UserRole::ExpertTeacher])) {
                return;
            }
            throw AssessmentException::unauthorized();
        }
        $institution = $assessment->getInstitution();
        if (!$institution instanceof Institution) {
            throw AssessmentException::unauthorized();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()) {
            throw AssessmentException::unauthorized();
        }
        if (\in_array($membership->getRole(), [InstitutionMembershipRole::Owner, InstitutionMembershipRole::Manager], true)) {
            return;
        }

        throw AssessmentException::unauthorized();
    }

    private function assertActorMayPublish(User $actor, Assessment $assessment): void
    {
        // ADMIN/MODERATOR do not auto-gain publish rights.
        $this->assertActorMayReview($actor, $assessment);
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
            throw AssessmentException::invalidInput('reason_code must be snake_case.');
        }

        return $reasonCode;
    }

    private function mapDriverException(\Throwable $throwable): never
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof DriverException && '45000' === $current->getSQLState()) {
                throw AssessmentException::immutable();
            }
            if ($current instanceof \Doctrine\DBAL\Driver\Exception && '45000' === $current->getSQLState()) {
                throw AssessmentException::immutable();
            }
        }

        throw $throwable;
    }

    private function uuidStringFromBinary(mixed $value): string
    {
        return $this->uuidFromDb($value)->toRfc4122();
    }

    private function uuidFromDb(mixed $value): Uuid
    {
        if (!\is_string($value)) {
            throw AssessmentException::conflict();
        }
        if (16 === \strlen($value)) {
            return Uuid::fromBinary($value);
        }

        return Uuid::fromString($value);
    }
}

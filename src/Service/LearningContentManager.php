<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\Institution;
use App\Entity\InstitutionMembership;
use App\Entity\LearningContent;
use App\Entity\LearningContentOutcomeAlignment;
use App\Entity\LearningContentPublication;
use App\Entity\LearningContentRevision;
use App\Entity\LearningContentRevisionAsset;
use App\Entity\LearningContentRevisionPrimaryAlignmentGuard;
use App\Entity\StoredMediaAsset;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\LearningContentRevisionAssetRole;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentSourceType;
use App\Enum\LearningContentStatus;
use App\Enum\LearningContentType;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Exception\LearningContentException;
use App\LearningContent\Content\LearningContentDocument;
use App\LearningContent\Content\LearningContentDocumentValidator;
use App\LearningContent\Content\LearningContentHashBuilder;
use App\LearningContent\Content\LearningContentHasher;
use App\LearningContent\Content\LearningContentSourceReferencePolicy;
use App\Repository\LearningContentOutcomeAlignmentRepository;
use App\Repository\LearningContentPublicationRepository;
use App\Repository\LearningContentRepository;
use App\Repository\LearningContentRevisionAssetRepository;
use App\Repository\LearningContentRevisionPrimaryAlignmentGuardRepository;
use App\Repository\LearningContentRevisionRepository;
use App\Security\InstitutionAuthorizationCacheInvalidator;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Learning content lifecycle with sealed revisions and publication snapshots.
 *
 * Lock order (documented in docs/architecture-learning-content.md):
 * Institution → Subject → Curriculum/Program → Topic/Outcome → LearningContent
 * → Users UUID order → Revision → Alignment → Asset → RevisionAsset/guard/publication → Audit
 *
 * Snapshot IDs without locks first; then acquire locks in the order above.
 * Published pointer/status are owned by AFTER INSERT publication trigger — do not call publish().
 */
final class LearningContentManager
{
    public function __construct(
        private readonly LearningContentRepository $contents,
        private readonly LearningContentRevisionRepository $revisions,
        private readonly LearningContentPublicationRepository $publications,
        private readonly LearningContentOutcomeAlignmentRepository $alignments,
        private readonly LearningContentRevisionPrimaryAlignmentGuardRepository $primaryGuards,
        private readonly LearningContentRevisionAssetRepository $revisionAssets,
        private readonly LearningContentDocumentValidator $documentValidator,
        private readonly LearningContentHasher $contentHasher,
        private readonly LearningContentHashBuilder $hashBuilder,
        private readonly LearningContentSourceReferencePolicy $sourceReferencePolicy,
        private readonly LearningContentTitleNormalizer $titleNormalizer,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly InstitutionAuthorizationCacheInvalidator $authCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param LearningContentDocument|array<string, mixed>                             $structuredContent
     * @param list<array{learningOutcome: CurriculumLearningOutcome, isPrimary: bool}> $alignments
     * @param array<string, mixed>|null                                                $accessibilityMetadata
     */
    public function createDraft(
        User $actor,
        LearningContentScope $scope,
        ?Institution $institution,
        Subject $subject,
        GradeLevel $gradeLevel,
        LearningContentType $contentType,
        string $code,
        string $title,
        ?string $summary,
        LearningContentDocument|array $structuredContent,
        array $alignments,
        string $reasonCode,
        ?int $estimatedMinutes = null,
        string $language = 'tr',
        LearningContentSourceType $sourceType = LearningContentSourceType::Original,
        ?string $sourceReference = null,
        ?array $accessibilityMetadata = null,
    ): LearningContent {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $titles = $this->titleNormalizer->normalize($title);
        $code = $this->titleNormalizer->normalizeCode($code);
        $summary = $this->titleNormalizer->normalizeSummary($summary);
        $document = $this->normalizeDocument($structuredContent);
        $actorId = $actor->getId();
        $subjectId = $subject->getId();
        $institutionId = $institution?->getId();

        try {
            $content = $this->entityManager->wrapInTransaction(function () use (
                $actorId,
                $scope,
                $institutionId,
                $subjectId,
                $gradeLevel,
                $contentType,
                $code,
                $titles,
                $summary,
                $document,
                $alignments,
                $estimatedMinutes,
                $language,
                $sourceType,
                $sourceReference,
                $accessibilityMetadata,
                $reasonCode,
            ): LearningContent {
                $lockedInstitution = null;
                if (LearningContentScope::Institution === $scope) {
                    if (null === $institutionId) {
                        throw LearningContentException::scopeMismatch();
                    }
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $institutionId,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution) {
                        throw LearningContentException::notFound();
                    }
                } elseif (null !== $institutionId) {
                    throw LearningContentException::scopeMismatch();
                }

                $lockedSubject = $this->freshEntities->findFreshLockedSubject($subjectId, LockMode::PESSIMISTIC_WRITE);
                if (!$lockedSubject instanceof Subject || SubjectStatus::Active !== $lockedSubject->getStatus()) {
                    throw LearningContentException::invalidInput('Subject must be active.');
                }

                $outcomeBundle = $this->lockAlignmentOutcomes($alignments, $lockedSubject, allowDraftCurriculum: true);
                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw LearningContentException::userNotFound();
                }
                $this->assertActorMayCreate($freshActor, $scope, $lockedInstitution);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $content = LearningContent::createDraft(
                    $scope,
                    $lockedInstitution,
                    $lockedSubject,
                    $gradeLevel,
                    $contentType,
                    $code,
                    $titles['slug'],
                    $titles['title'],
                    $titles['normalizedTitle'],
                    $summary,
                    $freshActor,
                    $now,
                );
                $this->contents->save($content, false);
                $this->entityManager->flush();

                // MariaDB cannot defer FKs: content INSERT keeps null current until revision 1
                // exists; trg_lcr_ai_sync_current then owns the pointer in this same transaction.
                $revision = $this->persistRevisionBundle(
                    $content,
                    1,
                    $freshActor,
                    $document,
                    $estimatedMinutes,
                    $language,
                    $sourceType,
                    $sourceReference,
                    $accessibilityMetadata,
                    $outcomeBundle,
                    $now,
                );

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::LearningContentCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'learning_content_manager',
                        'reason_code' => $reasonCode,
                        'content_id' => $content->getId()->toRfc4122(),
                        'revision_id' => $revision->getId()->toRfc4122(),
                        'revision_number' => 1,
                        'subject_id' => $lockedSubject->getId()->toRfc4122(),
                        'grade_level' => $gradeLevel->value,
                        'institution_id' => $lockedInstitution?->getId()->toRfc4122(),
                        'status' => $content->getStatus()->value,
                        'alignment_count' => \count($alignments),
                        'schema_version' => $revision->getSchemaVersion(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $content;
            });
        } catch (LearningContentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw LearningContentException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateLearningContent($content->getId());

        return $content;
    }

    /**
     * @param LearningContentDocument|array<string, mixed>                             $structuredContent
     * @param list<array{learningOutcome: CurriculumLearningOutcome, isPrimary: bool}> $alignments
     * @param array<string, mixed>|null                                                $accessibilityMetadata
     */
    public function createRevision(
        LearningContent $content,
        User $actor,
        LearningContentDocument|array $structuredContent,
        array $alignments,
        string $reasonCode,
        ?int $estimatedMinutes = null,
        string $language = 'tr',
        LearningContentSourceType $sourceType = LearningContentSourceType::Original,
        ?string $sourceReference = null,
        ?array $accessibilityMetadata = null,
    ): LearningContentRevision {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $document = $this->normalizeDocument($structuredContent);
        $contentId = $content->getId();
        $actorId = $actor->getId();

        try {
            $revision = $this->entityManager->wrapInTransaction(function () use (
                $contentId,
                $actorId,
                $document,
                $alignments,
                $estimatedMinutes,
                $language,
                $sourceType,
                $sourceReference,
                $accessibilityMetadata,
                $reasonCode,
            ): LearningContentRevision {
                $snapshot = $this->fetchContentSnapshot($contentId);
                if (null === $snapshot) {
                    throw LearningContentException::notFound();
                }

                $lockedInstitution = $this->lockInstitutionFromSnapshot($snapshot);
                $lockedSubject = $this->freshEntities->findFreshLockedSubject(
                    Uuid::fromString($snapshot['subject_id']),
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedSubject instanceof Subject) {
                    throw LearningContentException::notFound();
                }

                $outcomeBundle = $this->lockAlignmentOutcomes($alignments, $lockedSubject, allowDraftCurriculum: true);

                $lockedContent = $this->freshEntities->findFreshLockedLearningContent(
                    $contentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedContent instanceof LearningContent) {
                    throw LearningContentException::notFound();
                }
                $this->assertContentSnapshotUnchanged($lockedContent, $snapshot);

                $users = $this->freshEntities->findFreshLockedUsers(
                    $this->uniqueSortedIds([$actorId, $lockedContent->getCreatedBy()->getId()]),
                    LockMode::PESSIMISTIC_READ,
                );
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw LearningContentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedContent);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $lockedContent->prepareForNewRevision($now);
                $revisionNumber = ($lockedContent->getCurrentRevisionNumber() ?? 0) + 1;
                $this->contents->save($lockedContent, false);

                $revision = $this->persistRevisionBundle(
                    $lockedContent,
                    $revisionNumber,
                    $freshActor,
                    $document,
                    $estimatedMinutes,
                    $language,
                    $sourceType,
                    $sourceReference,
                    $accessibilityMetadata,
                    $outcomeBundle,
                    $now,
                );

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::LearningContentRevisionCreated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'learning_content_manager',
                        'reason_code' => $reasonCode,
                        'content_id' => $lockedContent->getId()->toRfc4122(),
                        'revision_id' => $revision->getId()->toRfc4122(),
                        'revision_number' => $revisionNumber,
                        'institution_id' => $lockedInstitution?->getId()->toRfc4122(),
                        'status' => $lockedContent->getStatus()->value,
                        'alignment_count' => \count($alignments),
                        'schema_version' => $revision->getSchemaVersion(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $revision;
            });
        } catch (LearningContentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw LearningContentException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateLearningContent($contentId);

        return $revision;
    }

    /**
     * @param LearningContentDocument|array<string, mixed> $structuredContent
     * @param array<string, mixed>|null                    $accessibilityMetadata
     */
    public function updateUnsealedRevision(
        LearningContentRevision $revision,
        User $actor,
        LearningContentDocument|array $structuredContent,
        string $reasonCode,
        ?int $estimatedMinutes = null,
        string $language = 'tr',
        LearningContentSourceType $sourceType = LearningContentSourceType::Original,
        ?string $sourceReference = null,
        ?array $accessibilityMetadata = null,
    ): LearningContentRevision {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $document = $this->normalizeDocument($structuredContent);
        $revisionId = $revision->getId();
        $actorId = $actor->getId();

        try {
            $updated = $this->entityManager->wrapInTransaction(function () use (
                $revisionId,
                $actorId,
                $document,
                $estimatedMinutes,
                $language,
                $sourceType,
                $sourceReference,
                $accessibilityMetadata,
                $reasonCode,
            ): LearningContentRevision {
                $revisionMeta = $this->entityManager->getConnection()->fetchAssociative(
                    'SELECT r.id AS revision_id, r.content_id, lc.institution_id, lc.subject_id, r.revision_number
                       FROM learning_content_revisions r
                       INNER JOIN learning_contents lc ON lc.id = r.content_id
                      WHERE r.id = ?',
                    [$revisionId->toBinary()],
                    [ParameterType::BINARY],
                );
                if (false === $revisionMeta) {
                    throw LearningContentException::notFound();
                }
                $contentId = Uuid::fromBinary((string) $revisionMeta['content_id']);
                $subjectId = Uuid::fromBinary((string) $revisionMeta['subject_id']);
                $institutionId = null !== $revisionMeta['institution_id']
                    ? Uuid::fromBinary((string) $revisionMeta['institution_id'])
                    : null;

                if (null !== $institutionId) {
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $institutionId,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution) {
                        throw LearningContentException::notFound();
                    }
                }

                $lockedSubject = $this->freshEntities->findFreshLockedSubject(
                    $subjectId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedSubject instanceof Subject) {
                    throw LearningContentException::notFound();
                }

                $lockedContent = $this->freshEntities->findFreshLockedLearningContent(
                    $contentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedContent instanceof LearningContent) {
                    throw LearningContentException::notFound();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw LearningContentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedContent);

                $lockedRevision = $this->freshEntities->findFreshLockedLearningContentRevision(
                    $revisionId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedRevision instanceof LearningContentRevision) {
                    throw LearningContentException::notFound();
                }
                if ($lockedRevision->isSealed()) {
                    throw LearningContentException::revisionSealed();
                }
                if ($lockedContent->getCurrentRevisionNumber() !== $lockedRevision->getRevisionNumber()) {
                    throw LearningContentException::conflict();
                }

                $this->sourceReferencePolicy->assertValid($sourceReference);
                $this->documentValidator->validate($document);
                $payload = $this->hashBuilder->build(
                    LearningContentDocument::SCHEMA_VERSION,
                    $language,
                    $estimatedMinutes,
                    $sourceType->value,
                    $sourceReference,
                    $document->toArray(),
                    $accessibilityMetadata,
                );
                $hash = $this->contentHasher->hash($payload);

                $lockedRevision->replaceUnsealedContent(
                    $document->toArray(),
                    $estimatedMinutes,
                    $language,
                    $sourceType,
                    $sourceReference,
                    $accessibilityMetadata,
                    $hash,
                    LearningContentDocument::SCHEMA_VERSION,
                );
                $this->revisions->save($lockedRevision, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::LearningContentRevisionUpdated,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'learning_content_manager',
                        'reason_code' => $reasonCode,
                        'content_id' => $lockedContent->getId()->toRfc4122(),
                        'revision_id' => $lockedRevision->getId()->toRfc4122(),
                        'revision_number' => $lockedRevision->getRevisionNumber(),
                        'schema_version' => $lockedRevision->getSchemaVersion(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $lockedRevision;
            });
        } catch (LearningContentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw LearningContentException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateLearningContent($updated->getContent()->getId());

        return $updated;
    }

    public function submitForReview(LearningContent $content, User $actor, string $reasonCode): void
    {
        $this->transition($content, $actor, $reasonCode, SecurityAuditAction::LearningContentSubmittedForReview, true, static function (LearningContent $c, LearningContentRevision $r, \DateTimeImmutable $now): void {
            if (!$r->isSealed()) {
                $r->seal($now);
            }
            $c->submitForReview($now);
        });
    }

    public function returnToDraft(LearningContent $content, User $actor, string $reasonCode): void
    {
        $this->transition($content, $actor, $reasonCode, SecurityAuditAction::LearningContentReturnedToDraft, false, static function (LearningContent $c, LearningContentRevision $r, \DateTimeImmutable $now): void {
            $c->returnToDraft($now);
        }, requireReview: true);
    }

    public function archive(LearningContent $content, User $actor, string $reasonCode): void
    {
        $this->transition($content, $actor, $reasonCode, SecurityAuditAction::LearningContentArchived, false, static function (LearningContent $c, LearningContentRevision $r, \DateTimeImmutable $now): void {
            $c->archive($now);
        }, requireArchive: true);
    }

    public function publish(LearningContent $content, User $actor, string $reasonCode): void
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $contentId = $content->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use ($contentId, $actorId, $reasonCode): void {
                $snapshot = $this->fetchContentSnapshot($contentId);
                if (null === $snapshot) {
                    throw LearningContentException::notFound();
                }
                $lockedInstitution = $this->lockInstitutionFromSnapshot($snapshot);
                $lockedContent = $this->freshEntities->findFreshLockedLearningContent(
                    $contentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedContent instanceof LearningContent) {
                    throw LearningContentException::notFound();
                }
                $this->assertContentSnapshotUnchanged($lockedContent, $snapshot, full: true);

                if (null === $snapshot['current_revision_id']) {
                    throw LearningContentException::notFound();
                }
                $revision = $this->freshEntities->findFreshLockedLearningContentRevision(
                    Uuid::fromString($snapshot['current_revision_id']),
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$revision instanceof LearningContentRevision) {
                    throw LearningContentException::notFound();
                }
                if (!$revision->getContent()->getId()->equals($lockedContent->getId())) {
                    throw LearningContentException::conflict();
                }
                if (!$revision->isSealed()) {
                    throw LearningContentException::revisionNotSealed();
                }
                if ($snapshot['current_revision_number'] !== $revision->getRevisionNumber()) {
                    throw LearningContentException::conflict();
                }

                $this->assertContentHashMatchesRevision($revision);
                $this->assertPublishableAlignments($revision, $lockedContent);
                $this->assertPublishableReferencedAssets($revision, $lockedContent);

                $userIds = $this->uniqueSortedIds([
                    $actorId,
                    $revision->getCreatedBy()->getId(),
                    $lockedContent->getCreatedBy()->getId(),
                ]);
                $users = $this->freshEntities->findFreshLockedUsers($userIds, LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw LearningContentException::userNotFound();
                }
                $this->assertActorMayPublish($freshActor, $lockedContent);

                $revisionAuthor = $users[$revision->getCreatedBy()->getId()->toRfc4122()] ?? null;
                if (!$revisionAuthor instanceof User) {
                    throw LearningContentException::userNotFound();
                }
                if ($freshActor->getId()->equals($revisionAuthor->getId())) {
                    throw LearningContentException::reviewSeparation();
                }

                if (LearningContentStatus::InReview !== $lockedContent->getStatus()) {
                    throw LearningContentException::invalidTransition();
                }

                $publicationNumber = $this->nextPublicationNumber($contentId);
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $oldStatus = $lockedContent->getStatus()->value;

                // Published pointer/status are applied by AFTER INSERT trigger — do not mutate content here.
                $publication = LearningContentPublication::create(
                    $lockedContent,
                    $revision,
                    $publicationNumber,
                    $revision->getContentHash(),
                    $freshActor,
                    $revision->getSchemaVersion(),
                    $now,
                );
                $this->publications->save($publication, false);
                $this->entityManager->flush();

                $this->entityManager->refresh($lockedContent);
                $lockedContent = $this->freshEntities->findFreshLockedLearningContent(
                    $contentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedContent instanceof LearningContent) {
                    throw LearningContentException::notFound();
                }
                $this->assertPublishedPointerMatchesRevision($lockedContent, $revision);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::LearningContentPublished,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'learning_content_manager',
                        'reason_code' => $reasonCode,
                        'content_id' => $lockedContent->getId()->toRfc4122(),
                        'revision_id' => $revision->getId()->toRfc4122(),
                        'revision_number' => $revision->getRevisionNumber(),
                        'publication_id' => $publication->getId()->toRfc4122(),
                        'publication_number' => $publicationNumber,
                        'institution_id' => $lockedInstitution?->getId()->toRfc4122(),
                        'old_status' => $oldStatus,
                        'new_status' => $lockedContent->getStatus()->value,
                        'schema_version' => $revision->getSchemaVersion(),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (LearningContentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw LearningContentException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateLearningContent($contentId);
    }

    public function cloneAsNewRevision(LearningContent $content, User $actor, string $reasonCode): LearningContentRevision
    {
        $contentId = $content->getId();
        $source = $content->getCurrentRevision();
        if (!$source instanceof LearningContentRevision) {
            $number = $content->getCurrentRevisionNumber();
            if (null === $number) {
                throw LearningContentException::notFound();
            }
            $source = $this->revisions->findOneByContentAndNumber($content, $number);
        }
        if (!$source instanceof LearningContentRevision) {
            throw LearningContentException::notFound();
        }

        $alignments = [];
        foreach ($this->alignments->findByRevision($source) as $alignment) {
            $alignments[] = [
                'learningOutcome' => $alignment->getLearningOutcome(),
                'isPrimary' => $alignment->isPrimary(),
            ];
        }

        return $this->createRevision(
            $content,
            $actor,
            $source->getStructuredContent(),
            $alignments,
            $reasonCode,
            $source->getEstimatedMinutes(),
            $source->getLanguage(),
            LearningContentSourceType::Cloned,
            $source->getId()->toRfc4122(),
            $source->getAccessibilityMetadata(),
        );
    }

    /**
     * @param list<array{learningOutcome: CurriculumLearningOutcome, isPrimary: bool}> $alignments
     */
    public function alignOutcomes(
        LearningContentRevision $revision,
        User $actor,
        array $alignments,
        string $reasonCode,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $revisionId = $revision->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $revisionId,
                $actorId,
                $alignments,
                $reasonCode,
            ): void {
                $revisionMeta = $this->entityManager->getConnection()->fetchAssociative(
                    'SELECT r.id AS revision_id, r.content_id, lc.institution_id, lc.subject_id
                       FROM learning_content_revisions r
                       INNER JOIN learning_contents lc ON lc.id = r.content_id
                      WHERE r.id = ?',
                    [$revisionId->toBinary()],
                    [ParameterType::BINARY],
                );
                if (false === $revisionMeta) {
                    throw LearningContentException::notFound();
                }
                $contentId = Uuid::fromBinary((string) $revisionMeta['content_id']);
                $subjectId = Uuid::fromBinary((string) $revisionMeta['subject_id']);
                $institutionId = null !== $revisionMeta['institution_id']
                    ? Uuid::fromBinary((string) $revisionMeta['institution_id'])
                    : null;

                if (null !== $institutionId) {
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $institutionId,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution) {
                        throw LearningContentException::notFound();
                    }
                }

                $lockedSubject = $this->freshEntities->findFreshLockedSubject(
                    $subjectId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedSubject instanceof Subject) {
                    throw LearningContentException::notFound();
                }

                $outcomeBundle = $this->lockAlignmentOutcomes($alignments, $lockedSubject, allowDraftCurriculum: true);

                $lockedContent = $this->freshEntities->findFreshLockedLearningContent(
                    $contentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedContent instanceof LearningContent) {
                    throw LearningContentException::notFound();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw LearningContentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedContent);

                $lockedRevision = $this->freshEntities->findFreshLockedLearningContentRevision(
                    $revisionId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedRevision instanceof LearningContentRevision) {
                    throw LearningContentException::notFound();
                }
                if ($lockedRevision->isSealed()) {
                    throw LearningContentException::revisionSealed();
                }

                $existingGuard = $this->primaryGuards->find($lockedRevision->getId());
                if ($existingGuard instanceof LearningContentRevisionPrimaryAlignmentGuard) {
                    $this->primaryGuards->remove($existingGuard, false);
                }
                foreach ($this->alignments->findByRevision($lockedRevision) as $existing) {
                    $this->alignments->remove($existing, false);
                }
                $this->entityManager->flush();

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $this->persistAlignments($lockedRevision, $outcomeBundle, $now);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::LearningContentOutcomesAligned,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'learning_content_manager',
                        'reason_code' => $reasonCode,
                        'content_id' => $lockedContent->getId()->toRfc4122(),
                        'revision_id' => $lockedRevision->getId()->toRfc4122(),
                        'revision_number' => $lockedRevision->getRevisionNumber(),
                        'alignment_count' => \count($alignments),
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (LearningContentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw LearningContentException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateLearningContent($revision->getContent()->getId());
    }

    public function attachAsset(
        LearningContentRevision $revision,
        User $actor,
        StoredMediaAsset $asset,
        LearningContentRevisionAssetRole $role,
        int $position,
        string $reasonCode,
        ?string $altText = null,
        ?string $caption = null,
    ): LearningContentRevisionAsset {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $revisionId = $revision->getId();
        $assetId = $asset->getId();
        $actorId = $actor->getId();

        try {
            $link = $this->entityManager->wrapInTransaction(function () use (
                $revisionId,
                $assetId,
                $actorId,
                $role,
                $position,
                $altText,
                $caption,
                $reasonCode,
            ): LearningContentRevisionAsset {
                $revisionMeta = $this->entityManager->getConnection()->fetchAssociative(
                    'SELECT r.id AS revision_id, r.content_id, lc.institution_id, lc.subject_id
                       FROM learning_content_revisions r
                       INNER JOIN learning_contents lc ON lc.id = r.content_id
                      WHERE r.id = ?',
                    [$revisionId->toBinary()],
                    [ParameterType::BINARY],
                );
                if (false === $revisionMeta) {
                    throw LearningContentException::notFound();
                }
                $contentId = Uuid::fromBinary((string) $revisionMeta['content_id']);
                $subjectId = Uuid::fromBinary((string) $revisionMeta['subject_id']);
                $institutionId = null !== $revisionMeta['institution_id']
                    ? Uuid::fromBinary((string) $revisionMeta['institution_id'])
                    : null;

                if (null !== $institutionId) {
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $institutionId,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution) {
                        throw LearningContentException::notFound();
                    }
                }

                $lockedSubject = $this->freshEntities->findFreshLockedSubject(
                    $subjectId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedSubject instanceof Subject) {
                    throw LearningContentException::notFound();
                }

                $lockedContent = $this->freshEntities->findFreshLockedLearningContent(
                    $contentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedContent instanceof LearningContent) {
                    throw LearningContentException::notFound();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw LearningContentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedContent);

                $lockedRevision = $this->freshEntities->findFreshLockedLearningContentRevision(
                    $revisionId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedRevision instanceof LearningContentRevision || $lockedRevision->isSealed()) {
                    throw $lockedRevision?->isSealed()
                        ? LearningContentException::revisionSealed()
                        : LearningContentException::notFound();
                }

                $lockedAsset = $this->freshEntities->findFreshLockedStoredMediaAsset(
                    $assetId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedAsset instanceof StoredMediaAsset) {
                    throw LearningContentException::notFound();
                }
                $this->assertAssetTenantCompatible($lockedContent, $lockedAsset);

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $link = LearningContentRevisionAsset::create(
                    $lockedRevision,
                    $lockedAsset,
                    $role,
                    $position,
                    $altText,
                    $caption,
                    $now,
                );
                $this->revisionAssets->save($link, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::LearningContentAssetAttached,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'learning_content_manager',
                        'reason_code' => $reasonCode,
                        'content_id' => $lockedContent->getId()->toRfc4122(),
                        'revision_id' => $lockedRevision->getId()->toRfc4122(),
                        'asset_id' => $lockedAsset->getId()->toRfc4122(),
                        'asset_kind' => $lockedAsset->getKind()->value,
                        'asset_count' => 1,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();

                return $link;
            });
        } catch (LearningContentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw LearningContentException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateLearningContent($revision->getContent()->getId());

        return $link;
    }

    public function detachAsset(
        LearningContentRevisionAsset $revisionAsset,
        User $actor,
        string $reasonCode,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $linkId = $revisionAsset->getId();
        $actorId = $actor->getId();
        $contentId = $revisionAsset->getRevision()->getContent()->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use ($linkId, $actorId, $reasonCode): void {
                $linkMeta = $this->entityManager->getConnection()->fetchAssociative(
                    'SELECT la.id AS link_id, la.revision_id, r.content_id, lc.institution_id, lc.subject_id, la.asset_id
                       FROM learning_content_revision_assets la
                       INNER JOIN learning_content_revisions r ON r.id = la.revision_id
                       INNER JOIN learning_contents lc ON lc.id = r.content_id
                      WHERE la.id = ?',
                    [$linkId->toBinary()],
                    [ParameterType::BINARY],
                );
                if (false === $linkMeta) {
                    throw LearningContentException::notFound();
                }
                $revisionId = Uuid::fromBinary((string) $linkMeta['revision_id']);
                $contentId = Uuid::fromBinary((string) $linkMeta['content_id']);
                $subjectId = Uuid::fromBinary((string) $linkMeta['subject_id']);
                $institutionId = null !== $linkMeta['institution_id']
                    ? Uuid::fromBinary((string) $linkMeta['institution_id'])
                    : null;

                if (null !== $institutionId) {
                    $lockedInstitution = $this->freshEntities->findFreshLockedInstitution(
                        $institutionId,
                        LockMode::PESSIMISTIC_WRITE,
                    );
                    if (!$lockedInstitution instanceof Institution) {
                        throw LearningContentException::notFound();
                    }
                }

                $lockedSubject = $this->freshEntities->findFreshLockedSubject(
                    $subjectId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedSubject instanceof Subject) {
                    throw LearningContentException::notFound();
                }

                $lockedContent = $this->freshEntities->findFreshLockedLearningContent(
                    $contentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedContent instanceof LearningContent) {
                    throw LearningContentException::notFound();
                }

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw LearningContentException::userNotFound();
                }
                $this->assertActorMayManage($freshActor, $lockedContent);

                $lockedRevision = $this->freshEntities->findFreshLockedLearningContentRevision(
                    $revisionId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedRevision instanceof LearningContentRevision) {
                    throw LearningContentException::notFound();
                }
                if ($lockedRevision->isSealed()) {
                    throw LearningContentException::revisionSealed();
                }

                $link = $this->revisionAssets->find($linkId);
                if (!$link instanceof LearningContentRevisionAsset) {
                    throw LearningContentException::notFound();
                }

                $assetId = $link->getAsset()->getId()->toRfc4122();
                $this->revisionAssets->remove($link, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::LearningContentAssetDetached,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'learning_content_manager',
                        'reason_code' => $reasonCode,
                        'content_id' => $lockedContent->getId()->toRfc4122(),
                        'revision_id' => $lockedRevision->getId()->toRfc4122(),
                        'asset_id' => $assetId,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (LearningContentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw LearningContentException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateLearningContent($contentId);
    }

    /**
     * @param callable(LearningContent, LearningContentRevision, \DateTimeImmutable): void $mutator
     */
    private function transition(
        LearningContent $content,
        User $actor,
        string $reasonCode,
        SecurityAuditAction $action,
        bool $sealCurrent,
        callable $mutator,
        bool $requireReview = false,
        bool $requireArchive = false,
    ): void {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $contentId = $content->getId();
        $actorId = $actor->getId();

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $contentId,
                $actorId,
                $reasonCode,
                $action,
                $sealCurrent,
                $mutator,
                $requireReview,
                $requireArchive,
            ): void {
                $snapshot = $this->fetchContentSnapshot($contentId);
                if (null === $snapshot) {
                    throw LearningContentException::notFound();
                }
                $lockedInstitution = $this->lockInstitutionFromSnapshot($snapshot);
                $lockedSubject = $this->freshEntities->findFreshLockedSubject(
                    Uuid::fromString($snapshot['subject_id']),
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedSubject instanceof Subject) {
                    throw LearningContentException::notFound();
                }

                $lockedContent = $this->freshEntities->findFreshLockedLearningContent(
                    $contentId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$lockedContent instanceof LearningContent) {
                    throw LearningContentException::notFound();
                }
                $this->assertContentSnapshotUnchanged($lockedContent, $snapshot, full: true);

                $users = $this->freshEntities->findFreshLockedUsers([$actorId], LockMode::PESSIMISTIC_READ);
                $freshActor = $users[$actorId->toRfc4122()] ?? null;
                if (!$freshActor instanceof User) {
                    throw LearningContentException::userNotFound();
                }

                if ($requireArchive || $requireReview) {
                    $this->assertActorMayPublish($freshActor, $lockedContent);
                } else {
                    $this->assertActorMayManage($freshActor, $lockedContent);
                }

                if (null === $snapshot['current_revision_id']) {
                    throw LearningContentException::notFound();
                }
                $revision = $this->freshEntities->findFreshLockedLearningContentRevision(
                    Uuid::fromString($snapshot['current_revision_id']),
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$revision instanceof LearningContentRevision) {
                    throw LearningContentException::notFound();
                }

                $oldStatus = $lockedContent->getStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                if ($sealCurrent && !$revision->isSealed()) {
                    $revision->seal($now);
                    $this->revisions->save($revision, false);
                }
                $mutator($lockedContent, $revision, $now);
                $this->contents->save($lockedContent, false);

                $this->auditRecorder->record(new SecurityAuditContext(
                    action: $action,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'learning_content_manager',
                        'reason_code' => $reasonCode,
                        'content_id' => $lockedContent->getId()->toRfc4122(),
                        'revision_id' => $revision->getId()->toRfc4122(),
                        'revision_number' => $revision->getRevisionNumber(),
                        'institution_id' => $lockedInstitution?->getId()->toRfc4122(),
                        'old_status' => $oldStatus,
                        'new_status' => $lockedContent->getStatus()->value,
                    ],
                    captureRequestHashes: false,
                ), false);

                $this->entityManager->flush();
            });
        } catch (LearningContentException $e) {
            throw $e;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw LearningContentException::conflict();
        } catch (\Throwable $e) {
            $this->mapDriverException($e);
        }

        $this->authCache->invalidateLearningContent($contentId);
    }

    /**
     * @param list<array{learningOutcome: CurriculumLearningOutcome, isPrimary: bool}> $alignments
     *
     * @return list<array{outcome: CurriculumLearningOutcome, isPrimary: bool}>
     */
    private function lockAlignmentOutcomes(array $alignments, Subject $subject, bool $allowDraftCurriculum): array
    {
        if ([] === $alignments) {
            throw LearningContentException::alignmentInvalid('At least one alignment is required.');
        }

        $primaryCount = 0;
        $ids = [];
        foreach ($alignments as $spec) {
            if ($spec['isPrimary']) {
                ++$primaryCount;
            }
            $ids[] = $spec['learningOutcome']->getId();
        }
        if (1 !== $primaryCount) {
            throw LearningContentException::alignmentInvalid('Exactly one primary alignment is required.');
        }

        $sorted = $ids;
        usort($sorted, static fn (Uuid $a, Uuid $b): int => $a->toRfc4122() <=> $b->toRfc4122());
        $locked = [];
        foreach ($sorted as $id) {
            $outcome = $this->freshEntities->findFreshLockedCurriculumLearningOutcome(
                $id,
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$outcome instanceof CurriculumLearningOutcome) {
                throw LearningContentException::alignmentInvalid('Learning outcome not found.');
            }
            if (CurriculumContentStatus::Active !== $outcome->getStatus()) {
                throw LearningContentException::alignmentInvalid('Learning outcome must be active.');
            }
            $program = $outcome->getCurriculumProgram();
            if (!$program->getSubject()->getId()->equals($subject->getId())) {
                throw LearningContentException::alignmentInvalid('Alignment subject mismatch.');
            }
            if (CurriculumStatus::Published !== $program->getStatus()
                && !($allowDraftCurriculum && CurriculumStatus::Draft === $program->getStatus())
            ) {
                throw LearningContentException::curriculumNotPublished();
            }
            $locked[$id->toRfc4122()] = $outcome;
        }

        $bundle = [];
        foreach ($alignments as $spec) {
            $key = $spec['learningOutcome']->getId()->toRfc4122();
            $bundle[] = [
                'outcome' => $locked[$key],
                'isPrimary' => $spec['isPrimary'],
            ];
        }

        return $bundle;
    }

    /**
     * @param list<array{outcome: CurriculumLearningOutcome, isPrimary: bool}> $outcomeBundle
     * @param array<string, mixed>|null                                        $accessibilityMetadata
     */
    private function persistRevisionBundle(
        LearningContent $content,
        int $revisionNumber,
        User $actor,
        LearningContentDocument $document,
        ?int $estimatedMinutes,
        string $language,
        LearningContentSourceType $sourceType,
        ?string $sourceReference,
        ?array $accessibilityMetadata,
        array $outcomeBundle,
        \DateTimeImmutable $now,
    ): LearningContentRevision {
        $this->sourceReferencePolicy->assertValid($sourceReference);
        $this->documentValidator->validate($document);
        $payload = $this->hashBuilder->build(
            LearningContentDocument::SCHEMA_VERSION,
            $language,
            $estimatedMinutes,
            $sourceType->value,
            $sourceReference,
            $document->toArray(),
            $accessibilityMetadata,
        );
        $hash = $this->contentHasher->hash($payload);

        $revision = LearningContentRevision::create(
            $content,
            $revisionNumber,
            LearningContentDocument::SCHEMA_VERSION,
            $document->toArray(),
            $estimatedMinutes,
            $language,
            $sourceType,
            $sourceReference,
            $accessibilityMetadata,
            $hash,
            $actor,
            $now,
        );
        $this->revisions->save($revision, false);
        $this->entityManager->flush();

        // trg_lcr_ai_sync_current owns the DB current pointer; refresh so UoW matches.
        $this->entityManager->refresh($content);
        if ($content->getCurrentRevisionNumber() !== $revision->getRevisionNumber()
            || null === $content->getCurrentRevision()
            || !$content->getCurrentRevision()->getId()->equals($revision->getId())
        ) {
            throw LearningContentException::conflict();
        }

        $this->persistAlignments($revision, $outcomeBundle, $now);
        $this->entityManager->flush();

        return $revision;
    }

    /**
     * @param list<array{outcome: CurriculumLearningOutcome, isPrimary: bool}> $outcomeBundle
     */
    private function persistAlignments(
        LearningContentRevision $revision,
        array $outcomeBundle,
        \DateTimeImmutable $now,
    ): void {
        $primary = null;
        $position = 0;
        foreach ($outcomeBundle as $item) {
            $outcome = $item['outcome'];
            $alignment = LearningContentOutcomeAlignment::create(
                $revision,
                $outcome->getCurriculumProgram(),
                $outcome->getCurriculumProgram()->getSubject(),
                $outcome->getTopic(),
                $outcome,
                $item['isPrimary'],
                $position,
                $now,
            );
            $this->alignments->save($alignment, false);
            if ($item['isPrimary']) {
                $primary = $alignment;
            }
            ++$position;
        }
        if (!$primary instanceof LearningContentOutcomeAlignment) {
            throw LearningContentException::alignmentInvalid('Primary alignment missing.');
        }
        $this->entityManager->flush();
        $this->primaryGuards->save(LearningContentRevisionPrimaryAlignmentGuard::bind($revision, $primary), false);
    }

    private function assertPublishableAlignments(LearningContentRevision $revision, LearningContent $content): void
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT a.is_primary, a.subject_id, p.status AS program_status, o.status AS outcome_status
               FROM learning_content_outcome_alignments a
               INNER JOIN curriculum_programs p ON p.id = a.curriculum_program_id
               INNER JOIN curriculum_learning_outcomes o ON o.id = a.learning_outcome_id
              WHERE a.revision_id = ?',
            [$revision->getId()->toBinary()],
            [ParameterType::BINARY],
        );
        if ([] === $rows) {
            throw LearningContentException::alignmentInvalid('Published content requires alignments.');
        }
        $primary = 0;
        $contentSubject = $content->getSubject()->getId()->toBinary();
        foreach ($rows as $row) {
            if (1 === (int) $row['is_primary']) {
                ++$primary;
            }
            if ((string) $row['subject_id'] !== $contentSubject) {
                throw LearningContentException::alignmentInvalid('Alignment subject mismatch.');
            }
            if (CurriculumStatus::Published->value !== (string) $row['program_status']) {
                throw LearningContentException::curriculumNotPublished();
            }
            if (CurriculumContentStatus::Active->value !== (string) $row['outcome_status']) {
                throw LearningContentException::alignmentInvalid('Learning outcome must be active.');
            }
        }
        if (1 !== $primary) {
            throw LearningContentException::alignmentInvalid('Exactly one primary alignment is required.');
        }
    }

    private function assertContentHashMatchesRevision(LearningContentRevision $revision): void
    {
        $stored = $revision->getContentHash();
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $stored)) {
            throw LearningContentException::conflict();
        }

        $payload = $this->hashBuilder->build(
            $revision->getSchemaVersion(),
            $revision->getLanguage(),
            $revision->getEstimatedMinutes(),
            $revision->getSourceType()->value,
            $revision->getSourceReference(),
            $revision->getStructuredContent(),
            $revision->getAccessibilityMetadata(),
        );
        $recomputed = $this->contentHasher->hash($payload);
        if (!hash_equals($stored, $recomputed)) {
            throw LearningContentException::conflict();
        }
    }

    private function assertPublishableReferencedAssets(
        LearningContentRevision $revision,
        LearningContent $content,
    ): void {
        $mediaIds = $this->collectMediaIds($revision->getStructuredContent());
        foreach ($mediaIds as $mediaId) {
            $asset = $this->freshEntities->findFreshLockedStoredMediaAsset(
                $mediaId,
                LockMode::PESSIMISTIC_WRITE,
            );
            if (!$asset instanceof StoredMediaAsset) {
                throw LearningContentException::assetInvalid('Referenced media asset was not found.');
            }
            $this->assertAssetTenantCompatible($content, $asset);
            if (StoredMediaAssetStatus::Ready !== $asset->getStatus()) {
                throw LearningContentException::assetInvalid('Referenced media asset must be ready.');
            }
            if (\App\Enum\StoredMediaScanStatus::Clean !== $asset->getScanStatus()) {
                throw LearningContentException::assetInvalid('Referenced media asset must be clean.');
            }
        }
    }

    /**
     * @param array<string, mixed> $structuredContent
     *
     * @return list<Uuid>
     */
    private function collectMediaIds(array $structuredContent): array
    {
        $ids = [];
        $walk = static function (mixed $node) use (&$walk, &$ids): void {
            if (!\is_array($node)) {
                return;
            }
            if (isset($node['mediaId']) && \is_string($node['mediaId']) && Uuid::isValid($node['mediaId'])) {
                $ids[Uuid::fromString($node['mediaId'])->toRfc4122()] = Uuid::fromString($node['mediaId']);
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($structuredContent);
        $sorted = array_values($ids);
        usort($sorted, static fn (Uuid $a, Uuid $b): int => $a->toRfc4122() <=> $b->toRfc4122());

        return $sorted;
    }

    private function assertPublishedPointerMatchesRevision(
        LearningContent $content,
        LearningContentRevision $revision,
    ): void {
        if (LearningContentStatus::Published !== $content->getStatus()) {
            throw LearningContentException::conflict();
        }
        $published = $content->getPublishedRevision();
        if (!$published instanceof LearningContentRevision) {
            throw LearningContentException::conflict();
        }
        if (!$published->getId()->equals($revision->getId())) {
            throw LearningContentException::conflict();
        }
        if ($content->getPublishedRevisionNumber() !== $revision->getRevisionNumber()) {
            throw LearningContentException::conflict();
        }
    }

    private function assertAssetTenantCompatible(LearningContent $content, StoredMediaAsset $asset): void
    {
        if (StoredMediaAssetStatus::Archived === $asset->getStatus()) {
            throw LearningContentException::assetInvalid('Archived assets cannot be attached.');
        }
        if (LearningContentScope::Platform === $content->getScope()) {
            if ('platform' !== $asset->getScope()->value) {
                throw LearningContentException::scopeMismatch();
            }

            return;
        }
        $contentInstitution = $content->getInstitution();
        if (null === $contentInstitution) {
            throw LearningContentException::scopeMismatch();
        }
        if ('platform' === $asset->getScope()->value) {
            return;
        }
        $assetInstitution = $asset->getInstitution();
        if (null === $assetInstitution) {
            throw LearningContentException::scopeMismatch();
        }
        if (!$contentInstitution->getId()->equals($assetInstitution->getId())) {
            throw LearningContentException::scopeMismatch();
        }
    }

    private function assertActorMayCreate(User $actor, LearningContentScope $scope, ?Institution $institution): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw LearningContentException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }
        if (LearningContentScope::Platform === $scope) {
            if ($this->hasAnyRole($actor, [UserRole::HeadTeacher, UserRole::ExpertTeacher, UserRole::Teacher])) {
                return;
            }
            throw LearningContentException::unauthorized();
        }
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw LearningContentException::unauthorized();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw LearningContentException::unauthorized();
        }
        if (!\in_array($membership->getRole(), [
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
            InstitutionMembershipRole::Teacher,
        ], true)) {
            throw LearningContentException::unauthorized();
        }
    }

    private function assertActorMayManage(User $actor, LearningContent $content): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw LearningContentException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }
        if (LearningContentScope::Platform === $content->getScope()) {
            if ($this->hasAnyRole($actor, [UserRole::HeadTeacher, UserRole::ExpertTeacher])) {
                return;
            }
            if ($this->hasAnyRole($actor, [UserRole::Teacher])
                && $actor->getId()->equals($content->getCreatedBy()->getId())
                && \in_array($content->getStatus(), [LearningContentStatus::Draft, LearningContentStatus::InReview], true)
            ) {
                return;
            }
            throw LearningContentException::unauthorized();
        }
        $institution = $content->getInstitution();
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw LearningContentException::unauthorized();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw LearningContentException::unauthorized();
        }
        if (\in_array($membership->getRole(), [
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
        ], true)) {
            return;
        }
        if (InstitutionMembershipRole::Teacher === $membership->getRole()
            && $actor->getId()->equals($content->getCreatedBy()->getId())
        ) {
            return;
        }
        throw LearningContentException::unauthorized();
    }

    private function assertActorMayPublish(User $actor, LearningContent $content): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw LearningContentException::unauthorized();
        }
        if ($this->activeVerifiedUserPolicy->isSuperAdmin($actor)) {
            return;
        }
        if (LearningContentScope::Platform === $content->getScope()) {
            if ($this->hasAnyRole($actor, [UserRole::HeadTeacher, UserRole::ExpertTeacher])) {
                return;
            }
            throw LearningContentException::unauthorized();
        }
        $institution = $content->getInstitution();
        if (!$institution instanceof Institution || InstitutionStatus::Active !== $institution->getStatus()) {
            throw LearningContentException::unauthorized();
        }
        $membership = $this->freshEntities->findFreshMembershipForUser($actor->getId(), $institution->getId());
        if (!$membership instanceof InstitutionMembership
            || InstitutionMembershipStatus::Active !== $membership->getStatus()
        ) {
            throw LearningContentException::unauthorized();
        }
        if (\in_array($membership->getRole(), [
            InstitutionMembershipRole::Owner,
            InstitutionMembershipRole::Manager,
        ], true)) {
            return;
        }
        throw LearningContentException::unauthorized();
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

    /**
     * @param LearningContentDocument|array<string, mixed> $structuredContent
     */
    private function normalizeDocument(LearningContentDocument|array $structuredContent): LearningContentDocument
    {
        return $structuredContent instanceof LearningContentDocument
            ? $structuredContent
            : LearningContentDocument::fromArray($structuredContent);
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = strtolower(trim($reasonCode));
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw LearningContentException::invalidInput('reason_code format is invalid.');
        }

        return $reasonCode;
    }

    /**
     * @return array{
     *     id: string,
     *     scope: string,
     *     institution_id: ?string,
     *     subject_id: string,
     *     grade_level: int,
     *     status: string,
     *     current_revision_id: ?string,
     *     current_revision_number: ?int,
     *     created_by_id: string
     * }|null
     */
    private function fetchContentSnapshot(Uuid $contentId): ?array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT id, scope, institution_id, subject_id, grade_level, status,
                    current_revision_id, current_revision_number, created_by_id
             FROM learning_contents WHERE id = ?',
            [$contentId->toBinary()],
            [ParameterType::BINARY],
        );
        if (false === $row) {
            return null;
        }

        return [
            'id' => Uuid::fromBinary((string) $row['id'])->toRfc4122(),
            'scope' => (string) $row['scope'],
            'institution_id' => null !== $row['institution_id']
                ? Uuid::fromBinary((string) $row['institution_id'])->toRfc4122()
                : null,
            'subject_id' => Uuid::fromBinary((string) $row['subject_id'])->toRfc4122(),
            'grade_level' => (int) $row['grade_level'],
            'status' => (string) $row['status'],
            'current_revision_id' => null !== $row['current_revision_id']
                ? Uuid::fromBinary((string) $row['current_revision_id'])->toRfc4122()
                : null,
            'current_revision_number' => null !== $row['current_revision_number']
                ? (int) $row['current_revision_number']
                : null,
            'created_by_id' => Uuid::fromBinary((string) $row['created_by_id'])->toRfc4122(),
        ];
    }

    /**
     * @param array{
     *     id: string,
     *     scope: string,
     *     institution_id: ?string,
     *     subject_id: string,
     *     grade_level: int,
     *     status: string,
     *     current_revision_id: ?string,
     *     current_revision_number: ?int,
     *     created_by_id: string
     * } $snapshot
     */
    private function assertContentSnapshotUnchanged(LearningContent $content, array $snapshot, bool $full = false): void
    {
        if (!$content->getId()->equals(Uuid::fromString($snapshot['id']))) {
            throw LearningContentException::conflict();
        }
        if ($content->getScope()->value !== $snapshot['scope']) {
            throw LearningContentException::conflict();
        }
        $institutionId = $content->getInstitution()?->getId()?->toRfc4122();
        if ($institutionId !== $snapshot['institution_id']) {
            throw LearningContentException::conflict();
        }
        if (!$content->getSubject()->getId()->equals(Uuid::fromString($snapshot['subject_id']))) {
            throw LearningContentException::conflict();
        }
        if (!$full) {
            return;
        }
        if ($content->getGradeLevel()->value !== $snapshot['grade_level']) {
            throw LearningContentException::conflict();
        }
        if ($content->getStatus()->value !== $snapshot['status']) {
            throw LearningContentException::conflict();
        }
        if ($content->getCurrentRevisionNumber() !== $snapshot['current_revision_number']) {
            throw LearningContentException::conflict();
        }
        if (!$content->getCreatedBy()->getId()->equals(Uuid::fromString($snapshot['created_by_id']))) {
            throw LearningContentException::conflict();
        }
    }

    /**
     * @param array{scope: string, institution_id: ?string} $snapshot
     */
    private function lockInstitutionFromSnapshot(array $snapshot): ?Institution
    {
        if (LearningContentScope::Institution->value !== $snapshot['scope']) {
            return null;
        }
        if (null === $snapshot['institution_id']) {
            throw LearningContentException::scopeMismatch();
        }
        $locked = $this->freshEntities->findFreshLockedInstitution(
            Uuid::fromString($snapshot['institution_id']),
            LockMode::PESSIMISTIC_WRITE,
        );
        if (!$locked instanceof Institution) {
            throw LearningContentException::notFound();
        }

        return $locked;
    }

    private function nextPublicationNumber(Uuid $contentId): int
    {
        $max = $this->entityManager->getConnection()->fetchOne(
            'SELECT MAX(publication_number) FROM learning_content_publications WHERE content_id = ?',
            [$contentId->toBinary()],
            [ParameterType::BINARY],
        );

        return (false === $max || null === $max) ? 1 : ((int) $max) + 1;
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
        $sorted = array_values($map);
        usort($sorted, static fn (Uuid $a, Uuid $b): int => $a->toRfc4122() <=> $b->toRfc4122());

        return $sorted;
    }

    private function mapDriverException(\Throwable $throwable): never
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof DriverException && '45000' === $current->getSQLState()) {
                throw LearningContentException::immutable();
            }
            if ($current instanceof \Doctrine\DBAL\Driver\Exception && '45000' === $current->getSQLState()) {
                throw LearningContentException::immutable();
            }
        }

        throw $throwable;
    }
}

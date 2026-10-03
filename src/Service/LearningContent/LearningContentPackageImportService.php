<?php

declare(strict_types=1);

namespace App\Service\LearningContent;

use App\Dto\SecurityAuditContext;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\LearningContent;
use App\Entity\LearningContentRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Exception\LearningContentPackageException;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\CatalogTopicLessonRepository;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\LearningContentOutcomeAlignmentRepository;
use App\Repository\LearningContentRepository;
use App\Repository\SubjectRepository;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\LearningContentManager;
use App\Service\SecurityAuditRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Replaces one allowlisted placeholder lesson draft. It does not create a second
 * content row, and it does not review, seal, publish, or place the lesson.
 */
final class LearningContentPackageImportService
{
    private const MODE_VERIFY = 'verify';
    private const MODE_DRY_RUN = 'dry-run';
    private const MODE_APPLY = 'apply';
    private const OPERATION_REPLACE = 'replace_placeholder';
    private const OPERATION_NOOP = 'noop';
    private const OPERATION_BLOCKED = 'blocked';

    public function __construct(
        private readonly string $projectDir,
        private readonly LearningContentPackageAllowlist $allowlist,
        private readonly LearningContentPackageDocument $documents,
        private readonly SubjectRepository $subjects,
        private readonly CurriculumProgramRepository $programs,
        private readonly CurriculumLearningOutcomeRepository $outcomes,
        private readonly LearningContentRepository $contents,
        private readonly LearningContentOutcomeAlignmentRepository $alignments,
        private readonly CatalogTopicLessonRepository $placements,
        private readonly LearningContentManager $contentManager,
        private readonly ActiveVerifiedUserPolicy $actors,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $locks,
    ) {
    }

    public function execute(
        string $relativeDirectory,
        string $mode,
        ?string $expectedFingerprint,
        User $actor,
    ): LearningContentPackageReport {
        if (!\in_array($mode, [self::MODE_VERIFY, self::MODE_DRY_RUN, self::MODE_APPLY], true)) {
            throw LearningContentPackageException::rejected();
        }
        $apply = self::MODE_APPLY === $mode;
        if ($apply && (null === $expectedFingerprint || 1 !== preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint))) {
            throw LearningContentPackageException::fingerprintRequired();
        }

        $target = $this->allowlist->resolve($relativeDirectory);
        $loaded = $this->documents->load($this->projectDir, $target);

        $lock = $this->locks->createLock('app.learning_content.package_import', 120.0);
        if (!$lock->acquire()) {
            throw LearningContentPackageException::conflict();
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $before = $this->evidence($connection);
            $plan = $this->inspect($loaded, $actor, true);
            if ($apply) {
                if (!hash_equals($plan->planFingerprint, (string) $expectedFingerprint)) {
                    throw LearningContentPackageException::stalePlan();
                }
                if ($plan->conflicts > 0
                    || 1 !== $plan->applyReady
                    || !\in_array($plan->operation, [self::OPERATION_REPLACE, self::OPERATION_NOOP], true)
                ) {
                    throw LearningContentPackageException::conflict();
                }
                if (self::OPERATION_REPLACE === $plan->operation) {
                    $this->replacePlaceholder($plan, $loaded, $actor);
                }
                $after = $this->evidence($connection);
                if ($before !== $after) {
                    throw LearningContentPackageException::evidenceChanged();
                }
                $this->auditRecorder->resetRequestDedup();
                $this->audit($loaded, $plan->operation, $actor);
                $connection->commit();

                return $this->finish($plan, self::OPERATION_REPLACE === $plan->operation ? 1 : 0, self::OPERATION_NOOP === $plan->operation ? 1 : 0);
            }

            $connection->rollBack();

            return $this->finish($plan, 0, $plan->noop);
        } catch (LearningContentPackageException $exception) {
            $this->rollBack($connection);
            throw $exception;
        } catch (\Throwable) {
            $this->rollBack($connection);
            throw LearningContentPackageException::conflict();
        } finally {
            $lock->release();
        }
    }

    private function replacePlaceholder(
        LearningContentPackageReport $plan,
        LoadedLearningContentPackage $loaded,
        User $actor,
    ): void {
        $content = $plan->content;
        $revision = $plan->revision;
        if (!$content instanceof LearningContent || !$revision instanceof LearningContentRevision) {
            throw LearningContentPackageException::conflict();
        }

        $this->contentManager->updateUnsealedRevision(
            $revision,
            $actor,
            $loaded->document,
            'package_import',
        );
    }

    private function inspect(
        LoadedLearningContentPackage $loaded,
        User $actor,
        bool $lockRows,
    ): LearningContentPackageReport {
        $target = $loaded->target;
        $subject = $this->subjects->findOneByCode($target->subjectCode);
        $subjectFound = $subject instanceof Subject ? 1 : 0;
        $lockFailed = false;
        if ($subject instanceof Subject && $lockRows && !$this->lockSubject($subject)) {
            $subject = null;
            $lockFailed = true;
        }

        $program = null;
        $outcome = null;
        if ($subject instanceof Subject) {
            $program = $this->programs->findOneByIdentity(
                $subject,
                GradeLevel::from($target->gradeLevel),
                $target->programCode,
                $target->programVersion,
            );
            if ($program instanceof CurriculumProgram && $lockRows) {
                $lockedProgram = $this->entityManager->find(CurriculumProgram::class, $program->getId(), LockMode::PESSIMISTIC_WRITE);
                $program = $lockedProgram instanceof CurriculumProgram ? $lockedProgram : null;
            }
            if ($program instanceof CurriculumProgram && CurriculumStatus::Published === $program->getStatus()) {
                $outcome = $this->outcomes->findOneByProgramAndCode($program, $target->outcomeCode);
                if ($outcome instanceof CurriculumLearningOutcome && $lockRows) {
                    $lockedOutcome = $this->entityManager->find(CurriculumLearningOutcome::class, $outcome->getId(), LockMode::PESSIMISTIC_WRITE);
                    $outcome = $lockedOutcome instanceof CurriculumLearningOutcome ? $lockedOutcome : null;
                }
            }
        }
        $outcomeFound = $outcome instanceof CurriculumLearningOutcome
            && CurriculumContentStatus::Active === $outcome->getStatus()
            ? 1
            : 0;

        $matches = $this->contents->findPlatformByCode($target->stableCode);
        $contentFound = \count($matches);
        $content = 1 === $contentFound ? $matches[0] : null;
        if ($content instanceof LearningContent && $lockRows) {
            $locked = $this->entityManager->find(LearningContent::class, $content->getId(), LockMode::PESSIMISTIC_WRITE);
            $content = $locked instanceof LearningContent ? $locked : null;
            if (!$content instanceof LearningContent) {
                $contentFound = 0;
            }
        }

        $revision = null;
        $revisionFound = 0;
        $placeholder = 0;
        $identical = false;
        $blocksCurrent = 0;
        $contentState = 'missing';
        $diagnosis = LearningContentPackageDiagnosis::none();
        $reasons = [];
        if ($lockFailed) {
            $reasons[] = LearningContentPackageConflictReason::UNSUPPORTED_EXISTING_STATE;
        }
        if (1 !== $subjectFound) {
            $reasons[] = LearningContentPackageConflictReason::SUBJECT_MISMATCH;
        }
        if (1 !== $outcomeFound) {
            $reasons[] = LearningContentPackageConflictReason::OUTCOME_MISMATCH;
        }
        if (1 !== $contentFound) {
            $reasons[] = LearningContentPackageConflictReason::UNSUPPORTED_EXISTING_STATE;
        }
        if ($content instanceof LearningContent) {
            $revision = $content->getCurrentRevision();
            if ($revision instanceof LearningContentRevision && $lockRows) {
                $lockedRevision = $this->entityManager->find(LearningContentRevision::class, $revision->getId(), LockMode::PESSIMISTIC_WRITE);
                $revision = $lockedRevision instanceof LearningContentRevision ? $lockedRevision : null;
            }
            $revisionFound = $revision instanceof LearningContentRevision ? 1 : 0;
            if (!$revision instanceof LearningContentRevision) {
                $reasons[] = LearningContentPackageConflictReason::REVISION_NOT_CURRENT;
            } else {
                $canonical = $this->canonical($revision->getStructuredContent());
                $blocksCurrent = \is_string($canonical) ? \count($revision->getStructuredContent()['blocks'] ?? []) : 0;
                $contentState = \is_string($canonical) ? hash('sha256', $canonical) : 'invalid';
                $placeholder = $canonical === $this->canonical(LearningContentDocument::paragraph('[Taslak]')->toArray()) ? 1 : 0;
                $identical = $canonical === $this->canonical($loaded->document->toArray());
                $diagnosis = $this->diagnoseContent($content, $revision, $loaded, $actor, $outcome, 1 === $placeholder, $identical);
                $reasons = [...$reasons, ...$diagnosis->reasons];
            }
        }

        $expected = \count($loaded->document->blocks);
        $sortedReasons = LearningContentPackageConflictReason::uniqueSorted($reasons);
        $conflicts = \count($sortedReasons);
        $operation = self::OPERATION_BLOCKED;
        $blocksToReplace = 0;
        if (0 === $conflicts && $identical) {
            $operation = self::OPERATION_NOOP;
        } elseif (0 === $conflicts && 1 === $placeholder) {
            $operation = self::OPERATION_REPLACE;
            $blocksToReplace = $expected;
        } elseif (0 === $conflicts) {
            $sortedReasons = [LearningContentPackageConflictReason::REVISION_NOT_PLACEHOLDER];
            $conflicts = 1;
        }

        return new LearningContentPackageReport(
            packageFound: 1,
            fixtureChecksum: $loaded->fixtureChecksum,
            subjectFound: $subjectFound,
            outcomeFound: $outcomeFound,
            contentFound: $contentFound,
            revisionFound: $revisionFound,
            placeholderRevision: $placeholder,
            blocksExpected: $expected,
            blocksCurrent: $blocksCurrent,
            blocksToReplace: $blocksToReplace,
            conflicts: $conflicts,
            applyReady: (self::OPERATION_REPLACE === $operation || self::OPERATION_NOOP === $operation) ? 1 : 0,
            applied: 0,
            noop: self::OPERATION_NOOP === $operation ? 1 : 0,
            questionsTouched: 0,
            assessmentsTouched: 0,
            placementsTouched: 0,
            usersTouched: 0,
            planFingerprint: $this->fingerprint($loaded, $content, $revision, $placeholder, $identical, $blocksCurrent, $expected, $operation, $conflicts, $contentState, $sortedReasons, $diagnosis),
            operation: $operation,
            conflictReasons: $sortedReasons,
            ownerMatch: $diagnosis->ownerMatch,
            contentStatusMatch: $diagnosis->contentStatusMatch,
            revisionStatusMatch: $diagnosis->revisionStatusMatch,
            subjectMatch: $diagnosis->subjectMatch,
            gradeMatch: $diagnosis->gradeMatch,
            outcomeMatch: $diagnosis->outcomeMatch,
            stableCodeMatch: $diagnosis->stableCodeMatch,
            contentTypeMatch: $diagnosis->contentTypeMatch,
            titleMatch: $diagnosis->titleMatch,
            summaryMatch: $diagnosis->summaryMatch,
            placementAbsent: $diagnosis->placementAbsent,
            content: $content,
            revision: $revision,
        );
    }

    private function diagnoseContent(
        LearningContent $content,
        LearningContentRevision $revision,
        LoadedLearningContentPackage $loaded,
        User $actor,
        ?CurriculumLearningOutcome $outcome,
        bool $placeholder,
        bool $identical,
    ): LearningContentPackageDiagnosis {
        $target = $loaded->target;
        $ownerMatch = $this->ownsDraft($actor, $content) ? 1 : 0;
        $contentStatusMatch = LearningContentStatus::Draft === $content->getStatus() ? 1 : 0;
        $revisionStatusMatch = $revision->isSealed() ? 0 : 1;
        $subjectMatch = $content->getSubject()->getCode() === $target->subjectCode ? 1 : 0;
        $gradeMatch = $content->getGradeLevel() === GradeLevel::from($target->gradeLevel) ? 1 : 0;
        $stableCodeMatch = $content->getCode() === $target->stableCode ? 1 : 0;
        $contentTypeMatch = $content->getContentType() === $target->contentType ? 1 : 0;
        $titleMatch = $content->getTitle() === $target->title ? 1 : 0;
        $summaryMatch = $content->getSummary() === $target->summary ? 1 : 0;
        $placementAbsent = [] === $this->placements->findOrderedByLearningContent($content) ? 1 : 0;
        $reasons = [];

        if (1 !== $ownerMatch) {
            $reasons[] = LearningContentPackageConflictReason::ACTOR_NOT_OWNER;
        }
        if (LearningContentScope::Platform !== $content->getScope()) {
            $reasons[] = LearningContentPackageConflictReason::UNSUPPORTED_EXISTING_STATE;
        }
        if (1 !== $contentStatusMatch) {
            $reasons[] = LearningContentPackageConflictReason::CONTENT_NOT_DRAFT;
        }
        if (1 !== $contentTypeMatch) {
            $reasons[] = LearningContentPackageConflictReason::CONTENT_TYPE_MISMATCH;
        }
        if (1 !== $stableCodeMatch) {
            $reasons[] = LearningContentPackageConflictReason::STABLE_CODE_MISMATCH;
        }
        if (1 !== $titleMatch) {
            $reasons[] = LearningContentPackageConflictReason::TITLE_MISMATCH;
        }
        if (1 !== $summaryMatch) {
            $reasons[] = LearningContentPackageConflictReason::SUMMARY_MISMATCH;
        }
        if (1 !== $gradeMatch) {
            $reasons[] = LearningContentPackageConflictReason::GRADE_MISMATCH;
        }
        if (1 !== $subjectMatch) {
            $reasons[] = LearningContentPackageConflictReason::SUBJECT_MISMATCH;
        }
        if ($revision->isSealed()) {
            $reasons[] = LearningContentPackageConflictReason::REVISION_NOT_DRAFT;
        }
        if (1 !== $revision->getRevisionNumber()) {
            $reasons[] = LearningContentPackageConflictReason::UNSUPPORTED_EXISTING_STATE;
        }
        if (1 !== $this->countRevisions($content) || 0 !== $this->countPublications($content)) {
            $reasons[] = LearningContentPackageConflictReason::REVIEW_OR_PUBLISH_HISTORY_EXISTS;
        }
        if (1 !== $placementAbsent) {
            $reasons[] = LearningContentPackageConflictReason::PLACEMENT_EXISTS;
        }
        if (!$placeholder && !$identical) {
            $reasons[] = LearningContentPackageConflictReason::REVISION_NOT_PLACEHOLDER;
        }

        $rows = $this->alignments->findByRevision($revision);
        $alignmentOk = false;
        if ($outcome instanceof CurriculumLearningOutcome && 1 === \count($rows) && $rows[0]->isPrimary()) {
            $aligned = $rows[0]->getLearningOutcome();
            $program = $aligned->getCurriculumProgram();
            $alignmentOk = $aligned->getId()->toRfc4122() === $outcome->getId()->toRfc4122()
                && $aligned->getCode() === $target->outcomeCode
                && CurriculumContentStatus::Active === $aligned->getStatus()
                && $program->getCode() === $target->programCode
                && $program->getVersion() === $target->programVersion
                && CurriculumStatus::Published === $program->getStatus()
                && $program->getGradeLevel() === GradeLevel::from($target->gradeLevel)
                && $program->getSubject()->getCode() === $target->subjectCode;
        }
        $outcomeMatch = $alignmentOk ? 1 : 0;
        if (1 !== $outcomeMatch) {
            $reasons[] = LearningContentPackageConflictReason::OUTCOME_MISMATCH;
        }

        return new LearningContentPackageDiagnosis(
            LearningContentPackageConflictReason::uniqueSorted($reasons),
            $ownerMatch,
            $contentStatusMatch,
            $revisionStatusMatch,
            $subjectMatch,
            $gradeMatch,
            $outcomeMatch,
            $stableCodeMatch,
            $contentTypeMatch,
            $titleMatch,
            $summaryMatch,
            $placementAbsent,
        );
    }

    private function ownsDraft(User $actor, LearningContent $content): bool
    {
        if (!$this->actors->isActiveAndVerified($actor)) {
            return false;
        }
        if (!$actor->getId()->equals($content->getCreatedBy()->getId())) {
            return false;
        }
        $teacherRoles = [
            UserRole::Teacher->value,
            UserRole::ExpertTeacher->value,
            UserRole::HeadTeacher->value,
        ];

        return [] !== array_intersect($teacherRoles, $actor->getRoles());
    }

    private function lockSubject(Subject $subject): bool
    {
        return $this->entityManager->find(Subject::class, $subject->getId(), LockMode::PESSIMISTIC_WRITE) instanceof Subject;
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function canonical(?array $stored): ?string
    {
        if (!\is_array($stored)) {
            return null;
        }
        try {
            $document = LearningContentDocument::fromArray($stored);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $encoded = json_encode($document->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return \is_string($encoded) ? $encoded : null;
    }

    private function countRevisions(LearningContent $content): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM learning_content_revisions WHERE content_id = ?',
            [$content->getId()->toBinary()],
            [ParameterType::BINARY],
        );
    }

    private function countPublications(LearningContent $content): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM learning_content_publications WHERE content_id = ?',
            [$content->getId()->toBinary()],
            [ParameterType::BINARY],
        );
    }

    /**
     * @return array<string, int>
     */
    private function evidence(Connection $connection): array
    {
        $counts = [];
        foreach ([
            'questions',
            'question_answer_keys',
            'assessments',
            'assessment_attempts',
            'catalog_topic_lessons',
            'users',
        ] as $table) {
            $counts[$table] = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
        }

        return $counts;
    }

    /**
     * @param list<string> $conflictReasons
     */
    private function fingerprint(
        LoadedLearningContentPackage $loaded,
        ?LearningContent $content,
        ?LearningContentRevision $revision,
        int $placeholder,
        bool $identical,
        int $blocksCurrent,
        int $blocksExpected,
        string $operation,
        int $conflicts,
        string $contentState,
        array $conflictReasons,
        LearningContentPackageDiagnosis $diagnosis,
    ): string {
        $target = $loaded->target;
        $payload = [
            'fixture_checksum' => $loaded->fixtureChecksum,
            'package_key' => $target->packageKey,
            'subject_code' => $target->subjectCode,
            'grade_level' => $target->gradeLevel,
            'program_code' => $target->programCode,
            'program_version' => $target->programVersion,
            'outcome_code' => $target->outcomeCode,
            'stable_code' => $target->stableCode,
            'content_type' => $target->contentType->value,
            'content_status' => $content instanceof LearningContent ? $content->getStatus()->value : 'missing',
            'revision_number' => $revision instanceof LearningContentRevision ? $revision->getRevisionNumber() : 0,
            'sealed' => $revision instanceof LearningContentRevision && $revision->isSealed() ? 1 : 0,
            'placeholder' => $placeholder,
            'identical' => $identical ? 1 : 0,
            'blocks_current' => $blocksCurrent,
            'blocks_expected' => $blocksExpected,
            'operation' => $operation,
            'conflicts' => $conflicts,
            'content_state_sha256' => $contentState,
            'conflict_reasons' => $conflictReasons,
            'owner_match' => $diagnosis->ownerMatch,
            'content_status_match' => $diagnosis->contentStatusMatch,
            'revision_status_match' => $diagnosis->revisionStatusMatch,
            'subject_match' => $diagnosis->subjectMatch,
            'grade_match' => $diagnosis->gradeMatch,
            'outcome_match' => $diagnosis->outcomeMatch,
            'stable_code_match' => $diagnosis->stableCodeMatch,
            'content_type_match' => $diagnosis->contentTypeMatch,
            'title_match' => $diagnosis->titleMatch,
            'summary_match' => $diagnosis->summaryMatch,
            'placement_absent' => $diagnosis->placementAbsent,
        ];
        $encoded = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (!\is_string($encoded)) {
            throw LearningContentPackageException::conflict();
        }

        return hash('sha256', $encoded);
    }

    private function audit(LoadedLearningContentPackage $loaded, string $operation, User $actor): void
    {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::LearningContentPackageImported,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: [
                'package_key' => $loaded->target->packageKey,
                'fixture_checksum' => $loaded->fixtureChecksum,
                'stable_code' => $loaded->target->stableCode,
                'block_count' => \count($loaded->document->blocks),
                'operation' => $operation,
            ],
            captureRequestHashes: false,
        ), true);
    }

    private function finish(LearningContentPackageReport $plan, int $applied, int $noop): LearningContentPackageReport
    {
        return new LearningContentPackageReport(
            packageFound: $plan->packageFound,
            fixtureChecksum: $plan->fixtureChecksum,
            subjectFound: $plan->subjectFound,
            outcomeFound: $plan->outcomeFound,
            contentFound: $plan->contentFound,
            revisionFound: $plan->revisionFound,
            placeholderRevision: $plan->placeholderRevision,
            blocksExpected: $plan->blocksExpected,
            blocksCurrent: $plan->blocksCurrent,
            blocksToReplace: $plan->blocksToReplace,
            conflicts: $plan->conflicts,
            applyReady: $plan->applyReady,
            applied: $applied,
            noop: $noop,
            questionsTouched: 0,
            assessmentsTouched: 0,
            placementsTouched: 0,
            usersTouched: 0,
            planFingerprint: $plan->planFingerprint,
            operation: $plan->operation,
            conflictReasons: $plan->conflictReasons,
            ownerMatch: $plan->ownerMatch,
            contentStatusMatch: $plan->contentStatusMatch,
            revisionStatusMatch: $plan->revisionStatusMatch,
            subjectMatch: $plan->subjectMatch,
            gradeMatch: $plan->gradeMatch,
            outcomeMatch: $plan->outcomeMatch,
            stableCodeMatch: $plan->stableCodeMatch,
            contentTypeMatch: $plan->contentTypeMatch,
            titleMatch: $plan->titleMatch,
            summaryMatch: $plan->summaryMatch,
            placementAbsent: $plan->placementAbsent,
            content: null,
            revision: null,
        );
    }

    private function rollBack(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }
}

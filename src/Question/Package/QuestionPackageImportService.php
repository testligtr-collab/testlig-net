<?php

declare(strict_types=1);

namespace App\Question\Package;

use App\Dto\SecurityAuditContext;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionScope;
use App\Enum\QuestionSourceType;
use App\Enum\QuestionStatus;
use App\Enum\QuestionType;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\SubjectStatus;
use App\Enum\UserStatus;
use App\Exception\QuestionException;
use App\Exception\QuestionPackageException;
use App\Question\Content\QuestionContentDocument;
use App\Question\Import\QuestionCsvImportException;
use App\Question\Import\QuestionCsvParser;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\QuestionAnswerKeyRepository;
use App\Repository\QuestionRepository;
use App\Repository\QuestionRevisionAlignmentRepository;
use App\Repository\QuestionRevisionOptionRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\SubjectRepository;
use App\Security\AdminAuthorization;
use App\Service\QuestionManager;
use App\Service\SecurityAuditRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Imports one allowlisted question CSV as Draft rows. It does not review,
 * publish, place, or attach questions to an assessment.
 */
final class QuestionPackageImportService
{
    private const MODE_VERIFY = 'verify';
    private const MODE_DRY_RUN = 'dry-run';
    private const MODE_APPLY = 'apply';
    private const OPERATION_CREATE = 'create';
    private const OPERATION_NOOP = 'noop';
    private const OPERATION_BLOCKED = 'blocked';

    public function __construct(
        private readonly string $projectDir,
        private readonly QuestionPackageAllowlist $allowlist,
        private readonly QuestionCsvParser $parser,
        private readonly SubjectRepository $subjects,
        private readonly CurriculumProgramRepository $programs,
        private readonly CurriculumLearningOutcomeRepository $outcomes,
        private readonly QuestionRepository $questions,
        private readonly QuestionRevisionRepository $revisions,
        private readonly QuestionRevisionOptionRepository $options,
        private readonly QuestionRevisionAlignmentRepository $alignments,
        private readonly QuestionAnswerKeyRepository $answerKeys,
        private readonly QuestionManager $questionManager,
        private readonly AdminAuthorization $authorization,
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
    ): QuestionPackageReport {
        if (!\in_array($mode, [self::MODE_VERIFY, self::MODE_DRY_RUN, self::MODE_APPLY], true)) {
            throw QuestionPackageException::rejected();
        }
        $apply = self::MODE_APPLY === $mode;
        if ($apply && (null === $expectedFingerprint || 1 !== preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint))) {
            throw QuestionPackageException::fingerprintRequired();
        }

        $target = $this->allowlist->resolve($relativeDirectory);
        $loaded = $this->loadCsv($target);

        $lock = $this->locks->createLock('app.question.package_import', 120.0);
        if (!$lock->acquire()) {
            throw QuestionPackageException::conflict();
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $plan = $this->inspect($target, $loaded, $actor, $mode, true);
            if ($apply) {
                if (!hash_equals($plan->planFingerprint, (string) $expectedFingerprint)) {
                    throw QuestionPackageException::stalePlan();
                }
                if ($plan->conflicts > 0
                    || 1 !== $plan->applyReady
                    || !\in_array($plan->operation, [self::OPERATION_CREATE, self::OPERATION_NOOP], true)
                ) {
                    throw QuestionPackageException::conflict();
                }
                $created = 0;
                if (self::OPERATION_CREATE === $plan->operation) {
                    $created = $this->createMissing($plan, $loaded, $actor, $target);
                }
                $this->auditRecorder->resetRequestDedup();
                $this->audit($target, $loaded['checksum'], $plan->operation, $created, $actor);
                $connection->commit();

                return $this->finish(
                    $plan,
                    self::OPERATION_CREATE === $plan->operation ? 1 : 0,
                    self::OPERATION_NOOP === $plan->operation ? 1 : 0,
                    $created,
                );
            }

            $connection->rollBack();

            return $this->finish($plan, 0, $plan->noop, 0);
        } catch (QuestionPackageException $exception) {
            $this->rollBack($connection);
            throw $exception;
        } catch (\Throwable) {
            $this->rollBack($connection);
            throw QuestionPackageException::conflict();
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array{checksum: string, records: list<array{line: int, values: array<string, string>, column_error: bool}>, payloads: list<array{
     *     code: string,
     *     grade: int,
     *     subjectCode: string,
     *     outcomeCode: string,
     *     stem: string,
     *     options: list<string>,
     *     correctIndex: int,
     *     explanation: string
     * }>} $loaded
     */
    private function createMissing(
        QuestionPackageReport $plan,
        array $loaded,
        User $actor,
        QuestionPackageTarget $target,
    ): int {
        $present = $this->questions->findCodesPresent($plan->createCodes);
        if ([] !== $present) {
            throw QuestionPackageException::stalePlan();
        }

        $subject = $this->subjects->findOneByCode($target->subjectCode);
        $program = $subject instanceof Subject
            ? $this->programs->findOneByIdentity(
                $subject,
                GradeLevel::from($target->gradeLevel),
                $target->programCode,
                $target->programVersion,
            )
            : null;
        $outcome = $program instanceof CurriculumProgram
            ? $this->outcomes->findOneByProgramAndCode($program, $target->outcomeCode)
            : null;
        if (!$subject instanceof Subject || !$outcome instanceof CurriculumLearningOutcome) {
            throw QuestionPackageException::conflict();
        }

        $byCode = [];
        foreach ($loaded['payloads'] as $payload) {
            $byCode[$payload['code']] = $payload;
        }

        $created = 0;
        try {
            foreach ($plan->createCodes as $code) {
                $payload = $byCode[$code] ?? null;
                if (null === $payload) {
                    throw QuestionPackageException::conflict();
                }
                $options = [];
                foreach ($payload['options'] as $index => $text) {
                    $position = $index + 1;
                    $options[] = [
                        'stableKey' => 'opt_'.$position,
                        'content' => QuestionContentDocument::paragraph($text),
                        'position' => $position,
                    ];
                }
                $this->questionManager->createDraftQuestion(
                    $actor,
                    QuestionScope::Platform,
                    null,
                    $subject,
                    GradeLevel::from($payload['grade']),
                    QuestionType::SingleChoice,
                    QuestionContentDocument::paragraph($payload['stem']),
                    QuestionContentDocument::paragraph($payload['explanation'])->toArray(),
                    $options,
                    ['correctStableKey' => 'opt_'.($payload['correctIndex'] + 1)],
                    [['learningOutcome' => $outcome, 'isPrimary' => true]],
                    QuestionDifficulty::Medium,
                    'question_package_imported',
                    null,
                    QuestionSourceType::Original,
                    null,
                    $this->uuidFromCode($payload['code']),
                );
                ++$created;
            }
        } catch (QuestionException) {
            throw QuestionPackageException::conflict();
        }

        if ($created !== \count($plan->createCodes)) {
            throw QuestionPackageException::conflict();
        }

        return $created;
    }

    /**
     * @param array{checksum: string, records: list<array{line: int, values: array<string, string>, column_error: bool}>, payloads: list<array{
     *     code: string,
     *     grade: int,
     *     subjectCode: string,
     *     outcomeCode: string,
     *     stem: string,
     *     options: list<string>,
     *     correctIndex: int,
     *     explanation: string
     * }>} $loaded
     */
    private function inspect(
        QuestionPackageTarget $target,
        array $loaded,
        User $actor,
        string $mode,
        bool $lockRows,
    ): QuestionPackageReport {
        $reasons = [];
        $actorFound = 1;
        $actorActive = UserStatus::Active === $actor->getStatus() ? 1 : 0;
        $actorVerified = null !== $actor->getEmailVerifiedAt() ? 1 : 0;
        $actorAuthorized = $this->authorization->canAuthorQuestions($actor) ? 1 : 0;
        if (1 !== $actorActive || 1 !== $actorVerified) {
            $reasons[] = QuestionPackageConflictReason::ACTOR_NOT_AVAILABLE;
        } elseif (1 !== $actorAuthorized) {
            $reasons[] = QuestionPackageConflictReason::ACTOR_NOT_AUTHORIZED;
        }

        $checksum = $loaded['checksum'];
        $packageFound = 1;
        if ($checksum !== $target->fixtureChecksum) {
            $reasons[] = QuestionPackageConflictReason::FIXTURE_CHECKSUM_MISMATCH;
        }

        $subject = $this->subjects->findOneByCode($target->subjectCode);
        $subjectFound = $subject instanceof Subject ? 1 : 0;
        if ($subject instanceof Subject && $lockRows) {
            $lockedSubject = $this->entityManager->find(Subject::class, $subject->getId(), LockMode::PESSIMISTIC_WRITE);
            $subject = $lockedSubject instanceof Subject ? $lockedSubject : null;
            $subjectFound = $subject instanceof Subject ? 1 : 0;
        }
        if (!$subject instanceof Subject) {
            $reasons[] = QuestionPackageConflictReason::SUBJECT_NOT_FOUND;
        } elseif (SubjectStatus::Active !== $subject->getStatus()) {
            $reasons[] = QuestionPackageConflictReason::SUBJECT_INACTIVE;
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
        if (0 === $outcomeFound) {
            $reasons[] = QuestionPackageConflictReason::OUTCOME_NOT_FOUND;
        } elseif (!$this->outcomeMatches($outcome, $target, $subject)) {
            $reasons[] = QuestionPackageConflictReason::OUTCOME_MISMATCH;
            $outcomeFound = 0;
        }

        $rowsParsed = \count($loaded['records']);
        $payloads = $loaded['payloads'];
        if ($rowsParsed !== $target->expectedRows || \count($payloads) !== $target->expectedRows) {
            $reasons[] = QuestionPackageConflictReason::CSV_INVALID;
        }

        $createCodes = [];
        $missing = 0;
        $matching = 0;
        $conflicting = 0;
        $existingByCode = [];
        foreach ($this->questions->findByCodes($target->expectedCodes) as $question) {
            $existingByCode[$question->getCode()] = $question;
        }

        foreach ($payloads as $payload) {
            $code = $payload['code'];
            $existing = $existingByCode[$code] ?? null;
            if (!$existing instanceof Question) {
                ++$missing;
                $createCodes[] = $code;
                continue;
            }
            if ($lockRows) {
                $locked = $this->entityManager->find(Question::class, $existing->getId(), LockMode::PESSIMISTIC_WRITE);
                $existing = $locked instanceof Question ? $locked : null;
            }
            if (!$existing instanceof Question) {
                $reasons[] = QuestionPackageConflictReason::UNEXPECTED_EXISTING_STATE;
                ++$conflicting;
                continue;
            }
            $rowReasons = $this->existingReasons($existing, $payload, $actor, $target);
            if ([] === $rowReasons) {
                ++$matching;
                continue;
            }
            ++$conflicting;
            foreach ($rowReasons as $reason) {
                $reasons[] = $reason;
            }
        }

        $reasons = $this->uniqueReasons($reasons);
        $conflicts = \count($reasons);
        $operation = self::OPERATION_BLOCKED;
        $applyReady = 0;
        $noop = 0;
        if (0 === $conflicts && $checksum === $target->fixtureChecksum) {
            if ($missing === $target->expectedRows && 0 === $matching) {
                $operation = self::OPERATION_CREATE;
                $applyReady = 1;
            } elseif (0 === $missing && $matching === $target->expectedRows) {
                $operation = self::OPERATION_NOOP;
                $applyReady = 1;
                $noop = 1;
            } elseif ($missing + $matching === $target->expectedRows && $missing > 0) {
                $operation = self::OPERATION_CREATE;
                $applyReady = 1;
            }
        }

        $fingerprint = $this->fingerprint(
            $target,
            $checksum,
            $actor,
            $missing,
            $matching,
            $conflicting,
            $createCodes,
            $reasons,
            $operation,
            $existingByCode,
        );

        return new QuestionPackageReport(
            mode: $mode,
            packageKey: $target->packageKey,
            fixtureChecksum: $checksum,
            packageFound: $packageFound,
            actorFound: $actorFound,
            actorActive: $actorActive,
            actorVerified: $actorVerified,
            actorAuthorized: $actorAuthorized,
            subjectFound: $subjectFound,
            outcomeFound: $outcomeFound,
            rowsExpected: $target->expectedRows,
            rowsParsed: $rowsParsed,
            codesExpected: \count($target->expectedCodes),
            codesMissing: $missing,
            codesMatching: $matching,
            codesConflicting: $conflicting,
            questionsToCreate: \count($createCodes),
            revisionsToCreate: \count($createCodes),
            answerKeysToCreate: \count($createCodes),
            questionsNoop: $matching,
            conflicts: $conflicts,
            applyReady: $applyReady,
            applied: 0,
            noop: $noop,
            questionsCreated: 0,
            revisionsCreated: 0,
            answerKeysCreated: 0,
            questionsSubmitted: 0,
            questionsPublished: 0,
            assessmentsTouched: 0,
            assessmentItemsTouched: 0,
            attemptsTouched: 0,
            usersTouched: 0,
            planFingerprint: $fingerprint,
            operation: $operation,
            conflictReasons: $reasons,
            createCodes: $createCodes,
        );
    }

    /**
     * @param array{
     *     code: string,
     *     grade: int,
     *     subjectCode: string,
     *     outcomeCode: string,
     *     stem: string,
     *     options: list<string>,
     *     correctIndex: int,
     *     explanation: string
     * } $payload
     *
     * @return list<string>
     */
    private function existingReasons(
        Question $question,
        array $payload,
        User $actor,
        QuestionPackageTarget $target,
    ): array {
        $reasons = [];
        if (QuestionScope::Platform !== $question->getScope() || null !== $question->getInstitution()) {
            $reasons[] = QuestionPackageConflictReason::UNEXPECTED_EXISTING_STATE;
        }
        if (!$question->getCreatedBy()->getId()->equals($actor->getId())) {
            $reasons[] = QuestionPackageConflictReason::EXISTING_OWNER_MISMATCH;
        }
        if (QuestionStatus::Draft !== $question->getStatus()) {
            $reasons[] = QuestionPackageConflictReason::EXISTING_STATUS_MISMATCH;
        }
        if ($question->getSubject()->getCode() !== $target->subjectCode) {
            $reasons[] = QuestionPackageConflictReason::EXISTING_SUBJECT_MISMATCH;
        }
        if ($question->getGradeLevel()->value !== $target->gradeLevel) {
            $reasons[] = QuestionPackageConflictReason::EXISTING_GRADE_MISMATCH;
        }

        $revision = $this->revisions->findForQuestionNumber($question, $question->getCurrentRevisionNumber());
        if (!$revision instanceof QuestionRevision) {
            $reasons[] = QuestionPackageConflictReason::UNEXPECTED_EXISTING_STATE;

            return $this->uniqueReasons($reasons);
        }
        if (QuestionType::SingleChoice !== $revision->getType()) {
            $reasons[] = QuestionPackageConflictReason::EXISTING_REVISION_MISMATCH;
        }
        if ($this->paragraphText($revision->getStemContent()) !== $payload['stem']) {
            $reasons[] = QuestionPackageConflictReason::EXISTING_REVISION_MISMATCH;
        }
        $explanation = $revision->getExplanationContent();
        if (!\is_array($explanation) || $this->paragraphText($explanation) !== $payload['explanation']) {
            $reasons[] = QuestionPackageConflictReason::EXISTING_EXPLANATION_MISMATCH;
        }

        $options = $this->options->findByRevision($revision);
        usort($options, static fn ($left, $right): int => $left->getPosition() <=> $right->getPosition());
        if (\count($options) !== \count($payload['options'])) {
            $reasons[] = QuestionPackageConflictReason::EXISTING_OPTIONS_MISMATCH;
        } else {
            foreach ($options as $index => $option) {
                $expectedKey = 'opt_'.($index + 1);
                if ($option->getStableKey() !== $expectedKey
                    || $option->getPosition() !== $index + 1
                    || $this->paragraphText($option->getContent()) !== $payload['options'][$index]
                ) {
                    $reasons[] = QuestionPackageConflictReason::EXISTING_OPTIONS_MISMATCH;
                    break;
                }
            }
        }

        $answer = $this->answerKeys->findOneByRevision($revision);
        $expectedKey = 'opt_'.($payload['correctIndex'] + 1);
        if (null === $answer || ($answer->getAnswerPayload()['correctStableKey'] ?? null) !== $expectedKey) {
            $reasons[] = QuestionPackageConflictReason::EXISTING_ANSWER_KEY_MISMATCH;
        }

        $alignments = $this->alignments->findByRevision($revision);
        $outcomeCodes = [];
        foreach ($alignments as $alignment) {
            $outcomeCodes[] = $alignment->getLearningOutcome()->getCode();
        }
        if (1 !== \count($outcomeCodes) || $outcomeCodes[0] !== $target->outcomeCode) {
            $reasons[] = QuestionPackageConflictReason::EXISTING_OUTCOME_MISMATCH;
        }

        return $this->uniqueReasons($reasons);
    }

    private function outcomeMatches(
        ?CurriculumLearningOutcome $outcome,
        QuestionPackageTarget $target,
        ?Subject $subject,
    ): bool {
        if (!$outcome instanceof CurriculumLearningOutcome || !$subject instanceof Subject) {
            return false;
        }
        $program = $outcome->getCurriculumProgram();

        return $outcome->getCode() === $target->outcomeCode
            && $program->getSubject()->getId()->equals($subject->getId())
            && $program->getGradeLevel()->value === $target->gradeLevel
            && $program->getCode() === $target->programCode
            && $program->getVersion() === $target->programVersion
            && CurriculumStatus::Published === $program->getStatus();
    }

    /**
     * @return array{checksum: string, records: list<array{line: int, values: array<string, string>, column_error: bool}>, payloads: list<array{
     *     code: string,
     *     grade: int,
     *     subjectCode: string,
     *     outcomeCode: string,
     *     stem: string,
     *     options: list<string>,
     *     correctIndex: int,
     *     explanation: string
     * }>}
     */
    private function loadCsv(QuestionPackageTarget $target): array
    {
        $relative = str_replace('/', \DIRECTORY_SEPARATOR, $target->directory).\DIRECTORY_SEPARATOR.'questions.csv';
        $path = $this->projectDir.\DIRECTORY_SEPARATOR.$relative;
        $real = realpath($path);
        $root = realpath($this->projectDir);
        if (!\is_string($real) || !\is_string($root) || !str_starts_with($real, $root.\DIRECTORY_SEPARATOR) || !is_file($real)) {
            throw QuestionPackageException::notAllowlisted();
        }

        $bytes = file_get_contents($real);
        if (!\is_string($bytes) || '' === $bytes) {
            throw QuestionPackageException::rejected();
        }
        $checksum = hash('sha256', $bytes);
        if ($checksum !== $target->fixtureChecksum) {
            return ['checksum' => $checksum, 'records' => [], 'payloads' => []];
        }
        if (!str_starts_with($bytes, "\xEF\xBB\xBF")) {
            throw QuestionPackageException::rejected();
        }

        try {
            $records = $this->parser->parse($bytes);
        } catch (QuestionCsvImportException) {
            throw QuestionPackageException::rejected();
        }

        $payloads = [];
        $seen = [];
        $answers = [];
        foreach ($records as $record) {
            if ($record['column_error'] || \count($record['values']) !== $target->expectedColumns) {
                throw QuestionPackageException::rejected();
            }
            $values = $record['values'];
            $code = $this->parser->normalizeQuestionCode($values['code']);
            if (null === $code || isset($seen[$code])) {
                throw QuestionPackageException::rejected();
            }
            $seen[$code] = true;
            if ($values['grade_level'] !== (string) $target->gradeLevel
                || $values['subject_code'] !== $target->subjectCode
                || $values['learning_outcome_code'] !== $target->outcomeCode
            ) {
                throw QuestionPackageException::rejected();
            }
            $options = [$values['option_a'], $values['option_b'], $values['option_c'], $values['option_d']];
            foreach ([$values['stem'], ...$options, $values['explanation']] as $cell) {
                if ('' === $cell || 1 === preg_match('/^[=+\-@\t]/', ltrim($cell))) {
                    throw QuestionPackageException::rejected();
                }
            }
            $correct = strtoupper($values['correct_option']);
            if (!\in_array($correct, ['A', 'B', 'C', 'D'], true)) {
                throw QuestionPackageException::rejected();
            }
            $answers[] = $correct;
            $payloads[] = [
                'code' => $code,
                'grade' => $target->gradeLevel,
                'subjectCode' => $target->subjectCode,
                'outcomeCode' => $target->outcomeCode,
                'stem' => $values['stem'],
                'options' => $options,
                'correctIndex' => \ord($correct) - \ord('A'),
                'explanation' => $values['explanation'],
            ];
        }

        if (array_keys($seen) !== $target->expectedCodes || $answers !== $target->expectedAnswers) {
            throw QuestionPackageException::rejected();
        }

        return ['checksum' => $checksum, 'records' => $records, 'payloads' => $payloads];
    }

    /**
     * @param list<string>            $createCodes
     * @param list<string>            $reasons
     * @param array<string, Question> $existingByCode
     */
    private function fingerprint(
        QuestionPackageTarget $target,
        string $checksum,
        User $actor,
        int $missing,
        int $matching,
        int $conflicting,
        array $createCodes,
        array $reasons,
        string $operation,
        array $existingByCode,
    ): string {
        $existing = [];
        foreach ($target->expectedCodes as $code) {
            $question = $existingByCode[$code] ?? null;
            $existing[$code] = $question instanceof Question
                ? [
                    'status' => $question->getStatus()->value,
                    'owner' => $question->getCreatedBy()->getId()->toRfc4122(),
                    'revision' => $question->getCurrentRevisionNumber(),
                    'subject' => $question->getSubject()->getCode(),
                    'grade' => $question->getGradeLevel()->value,
                ]
                : 'missing';
        }
        $payload = [
            'package_key' => $target->packageKey,
            'fixture_checksum' => $checksum,
            'expected_codes' => $target->expectedCodes,
            'expected_answers' => $target->expectedAnswers,
            'actor_id' => $actor->getId()->toRfc4122(),
            'mode_family' => 'inspect',
            'codes_missing' => $missing,
            'codes_matching' => $matching,
            'codes_conflicting' => $conflicting,
            'create_codes' => $createCodes,
            'conflict_reasons' => $reasons,
            'operation' => $operation,
            'existing' => $existing,
            'points_each' => $target->pointsEach,
            'question_type' => $target->questionType,
        ];
        $encoded = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (!\is_string($encoded)) {
            throw QuestionPackageException::conflict();
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function paragraphText(array $document): ?string
    {
        $blocks = $document['blocks'] ?? null;
        if (!\is_array($blocks) || 1 !== \count($blocks) || !\is_array($blocks[0] ?? null)) {
            return null;
        }
        $block = $blocks[0];

        return 'paragraph' === ($block['type'] ?? null) && \is_string($block['text'] ?? null)
            ? $block['text']
            : null;
    }

    /**
     * @param list<string> $reasons
     *
     * @return list<string>
     */
    private function uniqueReasons(array $reasons): array
    {
        $unique = [];
        foreach ($reasons as $reason) {
            $unique[$reason] = $reason;
        }
        ksort($unique);

        return array_values($unique);
    }

    private function uuidFromCode(string $code): Uuid
    {
        $formatted = substr($code, 0, 8).'-'.substr($code, 8, 4).'-'.substr($code, 12, 4).'-'.substr($code, 16, 4).'-'.substr($code, 20);

        return Uuid::fromString($formatted);
    }

    private function audit(
        QuestionPackageTarget $target,
        string $checksum,
        string $operation,
        int $created,
        User $actor,
    ): void {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: SecurityAuditAction::QuestionsBulkImported,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: [
                'source' => 'question_package_import',
                'reason_code' => 'question_package_imported',
                'package_key' => $target->packageKey,
                'fixture_checksum' => $checksum,
                'operation' => $operation,
                'question_count' => $created,
                'created_count' => $created,
            ],
            captureRequestHashes: false,
        ), true);
    }

    private function finish(
        QuestionPackageReport $plan,
        int $applied,
        int $noop,
        int $created,
    ): QuestionPackageReport {
        return new QuestionPackageReport(
            mode: $plan->mode,
            packageKey: $plan->packageKey,
            fixtureChecksum: $plan->fixtureChecksum,
            packageFound: $plan->packageFound,
            actorFound: $plan->actorFound,
            actorActive: $plan->actorActive,
            actorVerified: $plan->actorVerified,
            actorAuthorized: $plan->actorAuthorized,
            subjectFound: $plan->subjectFound,
            outcomeFound: $plan->outcomeFound,
            rowsExpected: $plan->rowsExpected,
            rowsParsed: $plan->rowsParsed,
            codesExpected: $plan->codesExpected,
            codesMissing: $plan->codesMissing,
            codesMatching: $plan->codesMatching,
            codesConflicting: $plan->codesConflicting,
            questionsToCreate: $plan->questionsToCreate,
            revisionsToCreate: $plan->revisionsToCreate,
            answerKeysToCreate: $plan->answerKeysToCreate,
            questionsNoop: $plan->questionsNoop,
            conflicts: $plan->conflicts,
            applyReady: $plan->applyReady,
            applied: $applied,
            noop: $noop,
            questionsCreated: $created,
            revisionsCreated: $created,
            answerKeysCreated: $created,
            questionsSubmitted: 0,
            questionsPublished: 0,
            assessmentsTouched: 0,
            assessmentItemsTouched: 0,
            attemptsTouched: 0,
            usersTouched: 0,
            planFingerprint: $plan->planFingerprint,
            operation: $plan->operation,
            conflictReasons: $plan->conflictReasons,
            createCodes: $plan->createCodes,
        );
    }

    private function rollBack(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }
}

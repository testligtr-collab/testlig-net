<?php

declare(strict_types=1);

namespace App\Service\CurriculumImport;

use App\Dto\SecurityAuditContext;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumStatus;
use App\Enum\GradeLevel;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\SubjectStatus;
use App\Exception\CurriculumImportException;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\CurriculumTopicRepository;
use App\Repository\CurriculumUnitRepository;
use App\Repository\SubjectRepository;
use App\Repository\UserRepository;
use App\Service\InstitutionalFreshEntityLoader;
use App\Service\InstitutionNameNormalizer;
use App\Service\SecurityAuditRecorder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Adds the verified TYMM-2026 grade-1 Matematik outcomes to the one published program.
 * This is not a general published-program editor. Curriculum managers stay draft-only.
 */
final class OfficialCurriculumReconcileService
{
    private const LOCK_KEY = 'app.curriculum.official_reconcile';
    private const SENTINEL = 100;

    public function __construct(
        private readonly OfficialCurriculumReconcileYamlLoader $loader,
        private readonly SubjectRepository $subjects,
        private readonly UserRepository $users,
        private readonly CurriculumProgramRepository $programs,
        private readonly CurriculumUnitRepository $units,
        private readonly CurriculumTopicRepository $topics,
        private readonly CurriculumLearningOutcomeRepository $outcomes,
        private readonly InstitutionNameNormalizer $names,
        private readonly SecurityAuditRecorder $audit,
        private readonly InstitutionalFreshEntityLoader $fresh,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LockFactory $locks,
    ) {
    }

    public function reconcile(string $absolutePath, bool $apply, ?string $expectedPlan = null): OfficialCurriculumReconcileResult
    {
        $document = $this->loader->loadFile($absolutePath);
        $result = new OfficialCurriculumReconcileResult();
        $result->dryRun = !$apply;
        $result->themesExpected = \count($document->themes);
        $result->topicsExpected = array_sum(array_map(static fn (array $theme): int => \count($theme['topics']), $document->themes));
        $result->outcomesExpected = \count($document->outcomes());

        $subject = $this->subjects->findOneByCode($document->subjectCode);
        if (!$subject instanceof Subject || SubjectStatus::Active !== $subject->getStatus()) {
            throw CurriculumImportException::notFound('Active canonical subject matematik was not found.');
        }
        $program = $this->programs->findOneByIdentity(
            $subject,
            GradeLevel::from($document->gradeLevel),
            $document->programCode,
            $document->programVersion,
        );
        if (!$program instanceof CurriculumProgram) {
            throw CurriculumImportException::notFound('Published TYMM-2026 grade-1 program was not found.');
        }
        if (CurriculumStatus::Published !== $program->getStatus()) {
            throw CurriculumImportException::conflict('Official reconcile requires the published program.');
        }
        $result->programFound = 1;

        $actor = $this->users->findOneActiveVerifiedSuperAdmin();
        if (!$actor instanceof User) {
            throw CurriculumImportException::notFound('Active verified SuperAdmin was not found.');
        }

        $lock = $this->locks->createLock(self::LOCK_KEY, 120.0);
        if (!$lock->acquire()) {
            throw CurriculumImportException::conflict('Official curriculum reconcile lock is busy.');
        }

        try {
            if (!$apply) {
                $plan = $this->inspect($document, $program, $result);
                $this->finishPlan($result, $plan);

                return $result;
            }
            if (!\is_string($expectedPlan) || 1 !== preg_match('/^[a-f0-9]{64}$/', $expectedPlan)) {
                throw CurriculumImportException::invalidInput('Apply requires the dry-run plan fingerprint.');
            }

            $this->entityManager->wrapInTransaction(function () use ($document, $program, $subject, $actor, $expectedPlan, $result): void {
                $lockedSubject = $this->fresh->findFreshLockedSubject($subject->getId(), LockMode::PESSIMISTIC_WRITE);
                $lockedProgram = $this->fresh->findFreshLockedCurriculumProgram($program->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$lockedSubject instanceof Subject || !$lockedProgram instanceof CurriculumProgram
                    || CurriculumStatus::Published !== $lockedProgram->getStatus()
                    || SubjectStatus::Active !== $lockedSubject->getStatus()) {
                    throw CurriculumImportException::conflict('Official reconcile target changed before apply.');
                }
                $before = $this->evidence($lockedProgram);
                $result->questionsAffected = $before['questions'];
                $result->assessmentsAffected = $before['assessments'];
                $plan = $this->inspect($document, $lockedProgram, $result);
                $this->finishPlan($result, $plan);
                if ($result->planFingerprint !== $expectedPlan) {
                    throw CurriculumImportException::conflict('Dry-run plan fingerprint does not match the locked program.');
                }
                if ($result->hasConflict() || 1 !== $result->applyReady) {
                    throw CurriculumImportException::conflict('Official reconcile plan has conflicts.');
                }
                if ([] === $plan['unitCreates'] && [] === $plan['unitReorders'] && [] === $plan['topicCreates'] && [] === $plan['outcomeCreates']) {
                    $result->noop = true;
                    $result->applied = false;

                    return;
                }
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $this->reorderUnits($plan['unitReorders'], $now);
                $unitsByCode = $this->indexUnits($lockedProgram);
                foreach ($plan['unitCreates'] as $theme) {
                    $names = $this->names->normalize($theme['title']);
                    $unit = CurriculumUnit::create($lockedProgram, $theme['code'], $names['name'], $names['normalizedName'], $theme['position'], null, $now);
                    $this->units->save($unit, false);
                    $unitsByCode[$theme['code']] = $unit;
                }
                $this->entityManager->flush();
                $this->reorderTopics($plan['topicReorders'], $now);
                foreach ($plan['topicCreates'] as $row) {
                    $unit = $unitsByCode[$row['unitCode']] ?? null;
                    if (!$unit instanceof CurriculumUnit) {
                        throw CurriculumImportException::conflict('Official theme was missing while creating a topic.');
                    }
                    $names = $this->names->normalize($row['title']);
                    $topic = CurriculumTopic::createRoot($unit, $row['code'], $names['name'], $names['normalizedName'], $row['position'], null, $now);
                    $this->topics->save($topic, false);
                }
                $this->entityManager->flush();
                $topics = $this->topicsFor($lockedProgram);
                foreach ($plan['outcomeCreates'] as $row) {
                    $topic = $topics[$row['unitCode'].'|'.$row['topicCode']] ?? null;
                    if (!$topic instanceof CurriculumTopic) {
                        throw CurriculumImportException::conflict('Official topic was missing while creating an outcome.');
                    }
                    $description = $this->text($row['description']);
                    $outcome = CurriculumLearningOutcome::create(
                        $topic,
                        $lockedProgram,
                        $row['code'],
                        $description,
                        mb_strtolower($description, 'UTF-8'),
                        $row['position'],
                        $now,
                    );
                    $this->outcomes->save($outcome, false);
                }
                $this->entityManager->flush();
                $after = $this->evidence($lockedProgram);
                if ($before !== $after) {
                    throw CurriculumImportException::conflict('Official reconcile touched questions, tests, or the catalog.');
                }
                $this->audit->record(new SecurityAuditContext(
                    action: SecurityAuditAction::CurriculumOfficialProgramReconciled,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $actor,
                    metadata: [
                        'source' => 'official_curriculum_reconcile',
                        'subject_code' => OfficialCurriculumReconcileDocument::SUBJECT_CODE,
                        'program_code' => OfficialCurriculumReconcileDocument::PROGRAM_CODE,
                        'source_version' => OfficialCurriculumReconcileDocument::SOURCE_VERSION,
                        'source_program_id' => OfficialCurriculumReconcileDocument::PROGRAM_ID,
                        'fixture_sha256' => OfficialCurriculumReconcileDocument::FIXTURE_SHA256,
                        'themes_created' => \count($plan['unitCreates']),
                        'themes_reordered' => \count($plan['unitReorders']),
                        'topics_created' => \count($plan['topicCreates']),
                        'outcomes_created' => \count($plan['outcomeCreates']),
                        'outcomes_skipped' => $result->outcomesSkip,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();
                $result->applied = true;
                $result->noop = false;
            });
        } catch (UniqueConstraintViolationException) {
            throw CurriculumImportException::conflict('Official reconcile hit a duplicate natural key and rolled back.');
        } finally {
            $lock->release();
        }

        return $result;
    }

    /**
     * @return array{
     *   unitCreates: list<array{position: int, code: string, title: string}>,
     *   unitReorders: list<array{unit: CurriculumUnit, position: int}>,
     *   topicCreates: list<array{unitCode: string, code: string, title: string, position: int}>,
     *   topicReorders: list<array{topic: CurriculumTopic, position: int}>,
     *   outcomeCreates: list<array{unitCode: string, topicCode: string, code: string, description: string, position: int}>
     * }
     */
    private function inspect(
        OfficialCurriculumReconcileDocument $document,
        CurriculumProgram $program,
        OfficialCurriculumReconcileResult $result,
    ): array {
        $result->themesFound = 0;
        $result->themesCreate = 0;
        $result->themesReorder = 0;
        $result->themesConflict = 0;
        $result->topicsFound = 0;
        $result->topicsCreate = 0;
        $result->topicsConflict = 0;
        $result->outcomesFound = 0;
        $result->outcomesCreate = 0;
        $result->outcomesSkip = 0;
        $result->outcomesConflict = 0;
        $result->unexpectedRecords = 0;
        $result->pilotPreserved = 0;

        $units = [];
        foreach ($this->units->findByProgram($program) as $unit) {
            $units[$unit->getCode()] = $unit;
        }
        $topicIndex = $this->topicsFor($program);
        $outcomes = [];
        foreach ($this->outcomes->findByProgram($program) as $outcome) {
            $outcomes[$outcome->getCode()] = $outcome;
        }

        $unitCreates = [];
        $unitReorders = [];
        $topicCreates = [];
        $topicReorders = [];
        $outcomeCreates = [];
        $expectedUnits = [];
        $expectedTopics = [];
        $expectedOutcomes = [];

        foreach ($document->themes as $theme) {
            $expectedUnits[$theme['code']] = $theme['position'];
            $unit = $units[$theme['code']] ?? null;
            if (!$unit instanceof CurriculumUnit) {
                ++$result->themesCreate;
                $unitCreates[] = ['position' => $theme['position'], 'code' => $theme['code'], 'title' => $theme['title']];
            } elseif (CurriculumContentStatus::Active !== $unit->getStatus() || $this->text($unit->getTitle()) !== $theme['title']) {
                ++$result->themesConflict;
                $result->line('conflict=theme code='.$theme['code']);
            } else {
                ++$result->themesFound;
                if ($unit->getPosition() !== $theme['position']) {
                    ++$result->themesReorder;
                    $unitReorders[] = ['unit' => $unit, 'position' => $theme['position']];
                }
            }

            foreach ($theme['topics'] as $topic) {
                $key = $theme['code'].'|'.$topic['code'];
                $expectedTopics[$key] = true;
                $existing = $topicIndex[$key] ?? null;
                if (!$existing instanceof CurriculumTopic) {
                    $foreign = $this->topicElsewhere($topicIndex, $topic['code'], $theme['code']);
                    if ($foreign) {
                        ++$result->topicsConflict;
                        $result->line('conflict=topic_code code='.$topic['code']);
                    } else {
                        ++$result->topicsCreate;
                        $topicCreates[] = [
                            'unitCode' => $theme['code'],
                            'code' => $topic['code'],
                            'title' => $topic['title'],
                            'position' => $topic['position'],
                        ];
                    }
                } elseif (CurriculumContentStatus::Active !== $existing->getStatus() || $this->text($existing->getTitle()) !== $topic['title']) {
                    ++$result->topicsConflict;
                    $result->line('conflict=topic code='.$topic['code']);
                } else {
                    ++$result->topicsFound;
                    if ($existing->getPosition() !== $topic['position']) {
                        $topicReorders[] = ['topic' => $existing, 'position' => $topic['position']];
                    }
                }

                $spec = $topic['outcome'];
                $expectedOutcomes[$spec['code']] = $key;
                $outcome = $outcomes[$spec['code']] ?? null;
                if (!$outcome instanceof CurriculumLearningOutcome) {
                    ++$result->outcomesCreate;
                    $outcomeCreates[] = [
                        'unitCode' => $theme['code'],
                        'topicCode' => $topic['code'],
                        'code' => $spec['code'],
                        'description' => $spec['description'],
                        'position' => $spec['position'],
                    ];
                    continue;
                }
                $actualTopic = $outcome->getTopic();
                $sameTopic = $actualTopic->getCode() === $topic['code'] && $actualTopic->getUnit()->getCode() === $theme['code'];
                $sameText = $this->text($outcome->getDescription()) === $spec['description'];
                if (!$sameTopic || !$sameText || CurriculumContentStatus::Active !== $outcome->getStatus() || $outcome->getPosition() !== $spec['position']) {
                    ++$result->outcomesConflict;
                    $result->line('conflict=outcome code='.$spec['code']);
                    continue;
                }
                ++$result->outcomesFound;
                ++$result->outcomesSkip;
                if (OfficialCurriculumReconcileDocument::PILOT_OUTCOME_CODE === $spec['code']) {
                    $result->pilotPreserved = 1;
                }
            }
        }

        foreach ($units as $code => $unit) {
            if (!isset($expectedUnits[$code])) {
                ++$result->unexpectedRecords;
                ++$result->themesConflict;
                $result->line('conflict=unexpected_theme code='.$code);
            }
        }
        foreach ($topicIndex as $key => $topic) {
            if (!isset($expectedTopics[$key])) {
                ++$result->unexpectedRecords;
                ++$result->topicsConflict;
                $result->line('conflict=unexpected_topic code='.$topic->getCode());
            }
        }
        foreach ($outcomes as $code => $outcome) {
            if (!isset($expectedOutcomes[$code])) {
                ++$result->unexpectedRecords;
                ++$result->outcomesConflict;
                $result->line('conflict=unexpected_outcome code='.$code);
            }
        }
        if (!$this->positionsFit($units, $expectedUnits, $unitReorders)) {
            ++$result->themesConflict;
            $result->line('conflict=theme_position');
        }

        $evidence = $this->evidence($program);
        $result->questionsAffected = $evidence['questions'];
        $result->assessmentsAffected = $evidence['assessments'];

        return [
            'unitCreates' => $unitCreates,
            'unitReorders' => $unitReorders,
            'topicCreates' => $topicCreates,
            'topicReorders' => $topicReorders,
            'outcomeCreates' => $outcomeCreates,
        ];
    }

    /**
     * @param array{
     *   unitCreates: list<array{position: int, code: string, title: string}>,
     *   unitReorders: list<array{unit: CurriculumUnit, position: int}>,
     *   topicCreates: list<array{unitCode: string, code: string, title: string, position: int}>,
     *   topicReorders: list<array{topic: CurriculumTopic, position: int}>,
     *   outcomeCreates: list<array{unitCode: string, topicCode: string, code: string, description: string, position: int}>
     * } $plan
     */
    private function finishPlan(OfficialCurriculumReconcileResult $result, array $plan): void
    {
        $result->applyReady = $result->hasConflict() ? 0 : 1;
        $result->planFingerprint = hash('sha256', json_encode([
            'fixture' => OfficialCurriculumReconcileDocument::FIXTURE_SHA256,
            'unitCreates' => array_column($plan['unitCreates'], 'code'),
            'unitReorders' => array_map(static fn (array $row): string => $row['unit']->getCode().':'.$row['position'], $plan['unitReorders']),
            'topicCreates' => array_map(static fn (array $row): string => $row['unitCode'].'|'.$row['code'], $plan['topicCreates']),
            'outcomeCreates' => array_column($plan['outcomeCreates'], 'code'),
            'skip' => $result->outcomesSkip,
            'conflicts' => $result->themesConflict + $result->topicsConflict + $result->outcomesConflict,
        ], \JSON_THROW_ON_ERROR));
        $result->line('program_found='.$result->programFound);
        $result->line('themes_expected='.$result->themesExpected.' found='.$result->themesFound.' create='.$result->themesCreate.' reorder='.$result->themesReorder.' conflict='.$result->themesConflict);
        $result->line('topics_expected='.$result->topicsExpected.' found='.$result->topicsFound.' create='.$result->topicsCreate.' conflict='.$result->topicsConflict);
        $result->line('outcomes_expected='.$result->outcomesExpected.' found='.$result->outcomesFound.' create='.$result->outcomesCreate.' skip='.$result->outcomesSkip.' conflict='.$result->outcomesConflict);
        $result->line('pilot_preserved='.$result->pilotPreserved);
        $result->line('unexpected_records='.$result->unexpectedRecords);
        $result->line('questions_affected='.$result->questionsAffected);
        $result->line('assessments_affected='.$result->assessmentsAffected);
        $result->line('apply_ready='.$result->applyReady);
        $result->line('plan_fingerprint='.$result->planFingerprint);
    }

    /**
     * @param array<string, CurriculumUnit>                    $units
     * @param array<string, int>                               $expected
     * @param list<array{unit: CurriculumUnit, position: int}> $reorders
     */
    private function positionsFit(array $units, array $expected, array $reorders): bool
    {
        $moving = [];
        foreach ($reorders as $row) {
            $moving[$row['unit']->getCode()] = $row['position'];
        }
        $occupied = [];
        foreach ($units as $unit) {
            if (!isset($expected[$unit->getCode()])) {
                return false;
            }
            $position = $moving[$unit->getCode()] ?? $unit->getPosition();
            if (isset($occupied[$position])) {
                return false;
            }
            $occupied[$position] = true;
            $sentinel = self::SENTINEL + $position;
            if ($unit->getPosition() >= self::SENTINEL) {
                return false;
            }
            foreach ($units as $other) {
                if (!isset($moving[$other->getCode()]) && $other->getPosition() === $sentinel) {
                    return false;
                }
            }
        }
        foreach ($expected as $code => $position) {
            if (!isset($units[$code]) && isset($occupied[$position])) {
                return false;
            }
            if (!isset($units[$code])) {
                $occupied[$position] = true;
            }
        }

        return true;
    }

    /**
     * @param list<array{unit: CurriculumUnit, position: int}> $reorders
     */
    private function reorderUnits(array $reorders, \DateTimeImmutable $now): void
    {
        if ([] === $reorders) {
            return;
        }
        foreach ($reorders as $row) {
            $row['unit']->reorder(self::SENTINEL + $row['position'], $now);
        }
        $this->entityManager->flush();
        foreach ($reorders as $row) {
            $row['unit']->reorder($row['position'], $now);
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<array{topic: CurriculumTopic, position: int}> $reorders
     */
    private function reorderTopics(array $reorders, \DateTimeImmutable $now): void
    {
        if ([] === $reorders) {
            return;
        }
        foreach ($reorders as $row) {
            $row['topic']->reorder(self::SENTINEL + $row['position'], $now);
        }
        $this->entityManager->flush();
        foreach ($reorders as $row) {
            $row['topic']->reorder($row['position'], $now);
        }
        $this->entityManager->flush();
    }

    /**
     * @return array<string, CurriculumUnit>
     */
    private function indexUnits(CurriculumProgram $program): array
    {
        $units = [];
        foreach ($this->units->findByProgram($program) as $unit) {
            $units[$unit->getCode()] = $unit;
        }

        return $units;
    }

    /**
     * @return array<string, CurriculumTopic>
     */
    private function topicsFor(CurriculumProgram $program): array
    {
        $index = [];
        foreach ($this->units->findByProgram($program) as $unit) {
            foreach ($this->topics->findByUnit($unit) as $topic) {
                $index[$unit->getCode().'|'.$topic->getCode()] = $topic;
            }
        }

        return $index;
    }

    /**
     * @param array<string, CurriculumTopic> $index
     */
    private function topicElsewhere(array $index, string $topicCode, string $unitCode): bool
    {
        foreach ($index as $key => $topic) {
            if ($topic->getCode() === $topicCode && !str_starts_with($key, $unitCode.'|')) {
                return true;
            }
        }

        return false;
    }

    private function text(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @return array{questions: int, assessments: int, catalog_subjects: int, catalog_units: int, catalog_topics: int, attempts: int}
     */
    private function evidence(CurriculumProgram $program): array
    {
        $id = $program->getId()->toBinary();
        $connection = $this->entityManager->getConnection();

        return [
            'questions' => (int) $connection->fetchOne(
                'SELECT COUNT(DISTINCT qr.question_id) FROM question_revision_alignments a INNER JOIN question_revisions qr ON qr.id = a.revision_id WHERE a.curriculum_program_id = ?',
                [$id],
            ),
            'assessments' => (int) $connection->fetchOne(
                'SELECT COUNT(DISTINCT ar.assessment_id) FROM assessment_items ai INNER JOIN assessment_revisions ar ON ar.id = ai.assessment_revision_id INNER JOIN question_revision_alignments a ON a.revision_id = ai.question_revision_id WHERE a.curriculum_program_id = ?',
                [$id],
            ),
            'catalog_subjects' => (int) $connection->fetchOne('SELECT COUNT(*) FROM catalog_subjects'),
            'catalog_units' => (int) $connection->fetchOne('SELECT COUNT(*) FROM catalog_units'),
            'catalog_topics' => (int) $connection->fetchOne('SELECT COUNT(*) FROM catalog_topics'),
            'attempts' => (int) $connection->fetchOne('SELECT COUNT(*) FROM assessment_attempts'),
        ];
    }
}

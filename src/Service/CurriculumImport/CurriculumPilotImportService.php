<?php

declare(strict_types=1);

namespace App\Service\CurriculumImport;

use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\CurriculumStatus;
use App\Enum\SubjectStatus;
use App\Exception\CurriculumException;
use App\Exception\CurriculumImportException;
use App\Exception\CurriculumTopicException;
use App\Exception\CurriculumUnitException;
use App\Exception\LearningOutcomeException;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\CurriculumProgramRepository;
use App\Repository\CurriculumTopicRepository;
use App\Repository\CurriculumUnitRepository;
use App\Repository\SubjectRepository;
use App\Repository\UserRepository;
use App\Service\CurriculumLearningOutcomeManager;
use App\Service\CurriculumProgramManager;
use App\Service\CurriculumTopicManager;
use App\Service\CurriculumUnitManager;
use Symfony\Component\Lock\LockFactory;

/**
 * Idempotent TYMM pilot curriculum import via domain managers (SA actor).
 *
 * Natural keys:
 * - program: subject.code + grade + program.code + version
 * - unit/topic/outcome: parent + code
 */
final class CurriculumPilotImportService
{
    private const LOCK_KEY = 'app.curriculum.pilot_import';

    public function __construct(
        private readonly CurriculumPilotImportYamlLoader $loader,
        private readonly SubjectRepository $subjects,
        private readonly UserRepository $users,
        private readonly CurriculumProgramRepository $programs,
        private readonly CurriculumUnitRepository $units,
        private readonly CurriculumTopicRepository $topics,
        private readonly CurriculumLearningOutcomeRepository $outcomes,
        private readonly CurriculumProgramManager $programManager,
        private readonly CurriculumUnitManager $unitManager,
        private readonly CurriculumTopicManager $topicManager,
        private readonly CurriculumLearningOutcomeManager $outcomeManager,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function import(string $absolutePath, bool $apply): CurriculumImportResult
    {
        $document = $this->loader->loadFile($absolutePath);
        $result = new CurriculumImportResult();
        $result->dryRun = !$apply;
        $result->applied = false;
        $result->line(\sprintf(
            'Fixture: subject=%s program=%s@%s outcome_official=%s version=%s',
            $document->subjectCode,
            $document->programCode,
            $document->programVersion,
            $document->outcomeOfficialCode,
            $document->programVersion,
        ));
        $result->line('Source: '.$document->sourceProgramUrl);
        if (null !== $document->sourceTymmThemeUrl) {
            $result->line('Theme: '.$document->sourceTymmThemeUrl);
        }

        $lock = $this->lockFactory->createLock(self::LOCK_KEY, 300.0);
        if (!$lock->acquire()) {
            throw CurriculumImportException::conflict('Müfredat pilot içe aktarma kilidi alınamadı.');
        }

        try {
            $subject = $this->subjects->findOneByCode($document->subjectCode);
            if (!$subject instanceof Subject || SubjectStatus::Active !== $subject->getStatus()) {
                ++$result->errors;
                throw CurriculumImportException::notFound(
                    \sprintf('Aktif canonical Subject bulunamadı (code=%s).', $document->subjectCode),
                );
            }

            $actor = $this->users->findOneActiveVerifiedSuperAdmin();
            if (!$actor instanceof User) {
                ++$result->errors;
                throw CurriculumImportException::notFound('Aktif doğrulanmış SuperAdmin bulunamadı.');
            }

            if (!$apply) {
                $this->planDryRun($document, $subject, $result);

                return $result;
            }

            $this->apply($document, $subject, $actor, $result);
            $result->applied = true;

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function planDryRun(
        CurriculumPilotImportDocument $document,
        Subject $subject,
        CurriculumImportResult $result,
    ): void {
        $program = $this->programs->findOneByIdentity(
            $subject,
            $document->gradeLevel,
            $document->programCode,
            $document->programVersion,
        );

        if (!$program instanceof CurriculumProgram) {
            ++$result->created;
            $result->line('would_create=program');
            ++$result->created;
            $result->line('would_create=unit');
            ++$result->created;
            $result->line('would_create=topic');
            ++$result->created;
            $result->line('would_create=outcome');
            if ($document->publish) {
                $result->line('would_publish=program');
            }

            return;
        }

        ++$result->skipped;
        $result->line('skip=program_exists status='.$program->getStatus()->value);

        $unit = $this->units->findOneByProgramAndCode($program, $document->unitCode);
        if (!$unit instanceof CurriculumUnit) {
            if (CurriculumStatus::Draft !== $program->getStatus()) {
                ++$result->conflicts;
                $result->line('conflict=unit_missing_on_non_draft_program');

                return;
            }
            ++$result->created;
            $result->line('would_create=unit');
        } else {
            ++$result->skipped;
            $result->line('skip=unit_exists');
        }

        $topic = null;
        if ($unit instanceof CurriculumUnit) {
            $topic = $this->topics->findOneByUnitAndCode($unit, $document->topicCode);
        }
        if (!$topic instanceof CurriculumTopic) {
            if (CurriculumStatus::Draft !== $program->getStatus()) {
                ++$result->conflicts;
                $result->line('conflict=topic_missing_on_non_draft_program');

                return;
            }
            ++$result->created;
            $result->line('would_create=topic');
        } else {
            ++$result->skipped;
            $result->line('skip=topic_exists');
        }

        $outcome = $this->outcomes->findOneByProgramAndCode($program, $document->outcomeCode);
        if (!$outcome instanceof CurriculumLearningOutcome) {
            if (CurriculumStatus::Draft !== $program->getStatus()) {
                ++$result->conflicts;
                $result->line('conflict=outcome_missing_on_non_draft_program');

                return;
            }
            ++$result->created;
            $result->line('would_create=outcome');
        } else {
            ++$result->skipped;
            $result->line('skip=outcome_exists');
        }

        if ($document->publish && CurriculumStatus::Published !== $program->getStatus()) {
            $result->line('would_publish=program');
        } elseif ($document->publish) {
            ++$result->skipped;
            $result->line('skip=program_already_published');
        }
    }

    private function apply(
        CurriculumPilotImportDocument $document,
        Subject $subject,
        User $actor,
        CurriculumImportResult $result,
    ): void {
        try {
            $program = $this->programs->findOneByIdentity(
                $subject,
                $document->gradeLevel,
                $document->programCode,
                $document->programVersion,
            );

            if (!$program instanceof CurriculumProgram) {
                $program = $this->programManager->createDraft(
                    $subject,
                    $actor,
                    $document->gradeLevel,
                    $document->programCode,
                    $document->programName,
                    $document->programVersion,
                    'curriculum_pilot_import',
                );
                ++$result->created;
                $result->line('created=program');
            } else {
                ++$result->skipped;
                $result->line('skip=program_exists status='.$program->getStatus()->value);
            }

            $unit = $this->units->findOneByProgramAndCode($program, $document->unitCode);
            if (!$unit instanceof CurriculumUnit) {
                $this->assertDraftForCreate($program, 'unit');
                $unit = $this->unitManager->create(
                    $program,
                    $actor,
                    $document->unitCode,
                    $document->unitTitle,
                    $document->unitPosition,
                    'curriculum_pilot_import',
                );
                ++$result->created;
                $result->line('created=unit official='.$document->unitOfficialThemeCode);
            } else {
                ++$result->skipped;
                $result->line('skip=unit_exists');
            }

            $topic = $this->topics->findOneByUnitAndCode($unit, $document->topicCode);
            if (!$topic instanceof CurriculumTopic) {
                $this->assertDraftForCreate($program, 'topic');
                $topic = $this->topicManager->createRoot(
                    $unit,
                    $actor,
                    $document->topicCode,
                    $document->topicTitle,
                    $document->topicPosition,
                    'curriculum_pilot_import',
                );
                ++$result->created;
                $result->line('created=topic');
            } else {
                ++$result->skipped;
                $result->line('skip=topic_exists');
            }

            $outcome = $this->outcomes->findOneByProgramAndCode($program, $document->outcomeCode);
            if (!$outcome instanceof CurriculumLearningOutcome) {
                $this->assertDraftForCreate($program, 'outcome');
                $this->outcomeManager->create(
                    $topic,
                    $actor,
                    $document->outcomeCode,
                    $document->outcomeDescription,
                    $document->outcomePosition,
                    'curriculum_pilot_import',
                );
                ++$result->created;
                $result->line('created=outcome official='.$document->outcomeOfficialCode);
            } else {
                ++$result->skipped;
                $result->line('skip=outcome_exists');
            }

            if ($document->publish) {
                if (CurriculumStatus::Published === $program->getStatus()) {
                    ++$result->skipped;
                    $result->line('skip=program_already_published');
                    $result->published = true;
                } else {
                    $this->programManager->publish($program, $actor, 'curriculum_pilot_import_publish');
                    $result->published = true;
                    $result->line('published=program');
                }
            }
        } catch (CurriculumException|CurriculumUnitException|CurriculumTopicException|LearningOutcomeException $e) {
            ++$result->errors;
            throw CurriculumImportException::conflict($e->getMessage());
        }
    }

    private function assertDraftForCreate(CurriculumProgram $program, string $entity): void
    {
        if (CurriculumStatus::Draft !== $program->getStatus()) {
            throw CurriculumImportException::conflict(
                \sprintf('Cannot create %s on non-draft curriculum program.', $entity),
            );
        }
    }
}

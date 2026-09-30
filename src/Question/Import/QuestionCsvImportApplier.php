<?php

declare(strict_types=1);

namespace App\Question\Import;

use App\Dto\SecurityAuditContext;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionScope;
use App\Enum\QuestionSourceType;
use App\Enum\QuestionType;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\QuestionException;
use App\Question\Content\QuestionContentDocument;
use App\Service\QuestionManager;
use App\Service\SecurityAuditRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Applies a previously dry-run plan through QuestionManager. The whole file is
 * one transaction: a later conflict rolls back every question and answer key.
 */
final class QuestionCsvImportApplier
{
    public function __construct(
        private readonly QuestionCsvImportPlanStore $plans,
        private readonly QuestionCsvImportPlanner $planner,
        private readonly QuestionCsvImportCatalog $catalog,
        private readonly QuestionManager $questions,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{created: int, skipped: int}
     */
    public function apply(User $actor, string $planId, bool $confirmed): array
    {
        if (!$confirmed) {
            throw new QuestionCsvImportException(QuestionCsvImportException::CONFIRMATION, 'Onay kutusunu işaretleyin.');
        }

        $stored = $this->plans->load($planId, $actor->getId()->toRfc4122());
        $plan = $this->planner->plan($stored['records']);
        if (!hash_equals($stored['decision_digest'], $plan->decisionDigest())) {
            $preserve = [];
            foreach ($stored['create_codes'] as $code) {
                $preserve[$code] = true;
            }
            $plan = $this->planner->plan($stored['records'], $preserve);
            if (!hash_equals($stored['decision_digest'], $plan->decisionDigest())) {
                throw new QuestionCsvImportException(QuestionCsvImportException::STALE, 'Önizleme güncelliğini yitirdi. Dosyayı yeniden yükleyin.');
            }
        }
        if ($plan->errorCount > 0 || $plan->conflictCount > 0 || !$plan->canApply()) {
            throw new QuestionCsvImportException(QuestionCsvImportException::CONFIRMATION, 'Hatalı bir satır varken içe aktarma yapılmaz.');
        }

        $created = 0;
        $skipped = $plan->skippedCount;
        $this->entityManager->wrapInTransaction(function () use ($actor, $plan, $planId, &$created): void {
            $questionCodes = [];
            $subjectCodes = [];
            $outcomeCodes = [];
            foreach ($plan->creates as $payload) {
                $questionCodes[] = $payload['code'];
                $subjectCodes[] = $payload['subjectCode'];
                $outcomeCodes[] = $payload['outcomeCode'];
            }
            $lookup = $this->catalog->lookup($subjectCodes, $outcomeCodes, $questionCodes);
            foreach ($questionCodes as $code) {
                if (isset($lookup->existingCodes[$code])) {
                    throw new QuestionCsvImportException(QuestionCsvImportException::CONFLICT, 'Aynı soru kodu aynı anda oluşturulduğu için içe aktarma geri alındı. Önizlemeyi yeniden çalıştırın.');
                }
            }

            try {
                foreach ($plan->creates as $payload) {
                    $subject = $lookup->subjects[$payload['subjectCode']] ?? null;
                    $outcome = $lookup->usableOutcomes[DoctrineQuestionCsvImportCatalog::outcomeKey(
                        $payload['subjectCode'],
                        $payload['grade'],
                        $payload['outcomeCode'],
                    )] ?? null;
                    if (null === $subject || null === $outcome) {
                        throw new QuestionCsvImportException(QuestionCsvImportException::CONFLICT, 'İçe aktarma geri alındı. Dosyayı yeniden yükleyin.');
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
                    $explanation = '' === $payload['explanation']
                        ? null
                        : QuestionContentDocument::paragraph($payload['explanation'])->toArray();
                    $this->questions->createDraftQuestion(
                        $actor,
                        QuestionScope::Platform,
                        null,
                        $subject,
                        GradeLevel::from($payload['grade']),
                        QuestionType::SingleChoice,
                        QuestionContentDocument::paragraph($payload['stem']),
                        $explanation,
                        $options,
                        ['correctStableKey' => 'opt_'.($payload['correctIndex'] + 1)],
                        [['learningOutcome' => $outcome, 'isPrimary' => true]],
                        QuestionDifficulty::Medium,
                        'question_csv_imported',
                        null,
                        QuestionSourceType::Original,
                        null,
                        $this->uuidFromCode($payload['code']),
                    );
                    ++$created;
                }
            } catch (QuestionException) {
                throw new QuestionCsvImportException(QuestionCsvImportException::CONFLICT, 'İçe aktarma geri alındı. Hiçbir soru oluşturulmadı.');
            }

            $this->auditRecorder->record(new SecurityAuditContext(
                action: SecurityAuditAction::QuestionsBulkImported,
                actorType: SecurityAuditActorType::User,
                outcome: SecurityAuditOutcome::Success,
                actorUser: $actor,
                metadata: [
                    'source' => 'question_csv_import',
                    'reason_code' => 'question_csv_imported',
                    'import_id' => $planId,
                    'created_count' => $created,
                    'skipped_count' => $plan->skippedCount,
                    'row_count' => \count($plan->rows),
                ],
                captureRequestHashes: false,
            ), false);
        });

        try {
            $this->plans->consume($planId, $actor->getId()->toRfc4122());
        } catch (QuestionCsvImportException) {
            // The domain transaction already committed. Unique question codes stop a second write.
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function uuidFromCode(string $code): Uuid
    {
        $formatted = substr($code, 0, 8).'-'.substr($code, 8, 4).'-'.substr($code, 12, 4).'-'.substr($code, 16, 4).'-'.substr($code, 20);

        return Uuid::fromString($formatted);
    }
}

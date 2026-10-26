<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Entity\User;
use App\Enum\CurriculumContentStatus;
use App\Enum\CurriculumOperation;
use App\Enum\CurriculumStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Exception\CurriculumDeletionException;
use App\Security\CurriculumActorPolicy;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Permanently removes one unused draft node.
 *
 * Children, content, questions, classroom courses, and alignments block the
 * delete. The counts are taken again inside the write transaction.
 */
final class CurriculumDraftDeletion
{
    public function __construct(
        private readonly CurriculumActorPolicy $actorPolicy,
        private readonly InstitutionalFreshEntityLoader $freshEntities,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function deleteProgram(CurriculumProgram $program, User $actor, string $reasonCode): void
    {
        $this->delete($actor, $reasonCode, $program->getSubject()->getId(), $program->getId(), null, null, null, 'program');
    }

    public function deleteUnit(CurriculumUnit $unit, User $actor, string $reasonCode): void
    {
        $program = $unit->getProgram();
        $this->delete($actor, $reasonCode, $program->getSubject()->getId(), $program->getId(), $unit->getId(), null, null, 'unit');
    }

    public function deleteTopic(CurriculumTopic $topic, User $actor, string $reasonCode): void
    {
        $program = $topic->getUnit()->getProgram();
        $this->delete($actor, $reasonCode, $program->getSubject()->getId(), $program->getId(), $topic->getUnit()->getId(), $topic->getId(), null, 'topic');
    }

    public function deleteOutcome(CurriculumLearningOutcome $outcome, User $actor, string $reasonCode): void
    {
        $program = $outcome->getCurriculumProgram();
        $this->delete(
            $actor,
            $reasonCode,
            $program->getSubject()->getId(),
            $program->getId(),
            $outcome->getUnit()->getId(),
            $outcome->getTopic()->getId(),
            $outcome->getId(),
            'outcome',
        );
    }

    private function delete(
        User $actor,
        string $reasonCode,
        Uuid $subjectId,
        Uuid $programId,
        ?Uuid $unitId,
        ?Uuid $topicId,
        ?Uuid $outcomeId,
        string $kind,
    ): void {
        $reasonCode = trim($reasonCode);
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $reasonCode)) {
            throw new CurriculumDeletionException('İşlem gerekçesi geçersiz.');
        }
        $actorId = $actor->getId();
        $rejected = null;

        try {
            $this->entityManager->wrapInTransaction(function () use (
                $actorId,
                $reasonCode,
                $subjectId,
                $programId,
                $unitId,
                $topicId,
                $outcomeId,
                $kind,
                &$rejected,
            ): void {
                try {
                    $subject = $this->freshEntities->findFreshLockedSubject($subjectId, LockMode::PESSIMISTIC_WRITE);
                    $program = $this->freshEntities->findFreshLockedCurriculumProgram($programId, LockMode::PESSIMISTIC_WRITE);
                    if (null === $subject || null === $program || !$program->getSubject()->getId()->equals($subject->getId())) {
                        throw new CurriculumDeletionException('Kayıt bulunamadı.');
                    }
                    $freshActor = $this->freshEntities->findFreshLockedUser($actorId, LockMode::PESSIMISTIC_READ);
                    if (!$freshActor instanceof User || !$this->actorPolicy->allowsFresh($freshActor, CurriculumOperation::Archive)) {
                        throw new CurriculumDeletionException('Bu işlem için yetkiniz yok.');
                    }
                    if (CurriculumStatus::Draft !== $program->getStatus()) {
                        throw new CurriculumDeletionException('Yayımlanmış veya emekli program silinmez. Geçmiş bağlantı için emekliye ayırma kullanılır.');
                    }

                    $targetId = $program->getId();
                    $code = $program->getCode();
                    $target = $program;
                    if ('unit' === $kind) {
                        $unit = $this->requireUnit($unitId, $program);
                        $this->assertNoChildren($this->count('curriculum_topics', 'unit_id', $unit->getId()), 'Alt konu veya kazanım olduğu için silinmedi.');
                        $this->assertActive($unit->getStatus());
                        $target = $unit;
                        $targetId = $unit->getId();
                        $code = $unit->getCode();
                    } elseif ('topic' === $kind) {
                        $topic = $this->requireTopic($topicId, $program);
                        $this->assertNoChildren($this->count('curriculum_topics', 'parent_id', $topic->getId()) + $this->count('curriculum_learning_outcomes', 'topic_id', $topic->getId()), 'Alt kayıt olduğu için silinmedi.');
                        $this->assertNoChildren($this->count('question_revision_alignments', 'curriculum_topic_id', $topic->getId()) + $this->count('learning_content_outcome_alignments', 'curriculum_topic_id', $topic->getId()), 'İçerik veya soru bağlantısı olduğu için silinmedi.');
                        $this->assertActive($topic->getStatus());
                        $target = $topic;
                        $targetId = $topic->getId();
                        $code = $topic->getCode();
                    } elseif ('outcome' === $kind) {
                        $outcome = $this->requireOutcome($outcomeId, $program);
                        $this->assertNoChildren($this->count('question_revision_alignments', 'learning_outcome_id', $outcome->getId()) + $this->count('learning_content_outcome_alignments', 'learning_outcome_id', $outcome->getId()), 'İçerik veya soru bağlantısı olduğu için silinmedi.');
                        $this->assertActive($outcome->getStatus());
                        $target = $outcome;
                        $targetId = $outcome->getId();
                        $code = $outcome->getCode();
                    } else {
                        $this->assertNoChildren($this->count('curriculum_units', 'curriculum_program_id', $program->getId()), 'Alt kayıt olduğu için silinmedi.');
                        $this->assertNoChildren($this->count('classroom_courses', 'curriculum_program_id', $program->getId()) + $this->count('question_revision_alignments', 'curriculum_program_id', $program->getId()) + $this->count('learning_content_outcome_alignments', 'curriculum_program_id', $program->getId()), 'İçerik, soru veya sınıf dersi bağlantısı olduğu için silinmedi.');
                    }
                } catch (CurriculumDeletionException $exception) {
                    $rejected = $exception->getMessage();

                    return;
                }

                $this->entityManager->remove($target);
                $this->auditRecorder->record(new SecurityAuditContext(
                    action: SecurityAuditAction::CurriculumDraftDeleted,
                    actorType: SecurityAuditActorType::User,
                    outcome: SecurityAuditOutcome::Success,
                    actorUser: $freshActor,
                    metadata: [
                        'source' => 'curriculum_draft_deletion',
                        'reason_code' => $reasonCode,
                        'target_type' => $kind,
                        'resource_id' => $targetId->toRfc4122(),
                        'curriculum_program_id' => $programId->toRfc4122(),
                        'code' => $code,
                    ],
                    captureRequestHashes: false,
                ), false);
                $this->entityManager->flush();
            });
        } catch (ForeignKeyConstraintViolationException) {
            throw new CurriculumDeletionException('Bağımlılık oluştuğu için kayıt silinmedi.');
        }

        if (null !== $rejected) {
            throw new CurriculumDeletionException($rejected);
        }
    }

    private function requireUnit(?Uuid $unitId, CurriculumProgram $program): CurriculumUnit
    {
        $unit = null === $unitId ? null : $this->freshEntities->findFreshLockedCurriculumUnit($unitId, LockMode::PESSIMISTIC_WRITE);
        if (!$unit instanceof CurriculumUnit || !$unit->getProgram()->getId()->equals($program->getId())) {
            throw new CurriculumDeletionException('Kayıt bu programda yok.');
        }

        return $unit;
    }

    private function requireTopic(?Uuid $topicId, CurriculumProgram $program): CurriculumTopic
    {
        $topic = null === $topicId ? null : $this->freshEntities->findFreshLockedCurriculumTopic($topicId, LockMode::PESSIMISTIC_WRITE);
        if (!$topic instanceof CurriculumTopic || !$topic->getUnit()->getProgram()->getId()->equals($program->getId())) {
            throw new CurriculumDeletionException('Kayıt bu programda yok.');
        }

        return $topic;
    }

    private function requireOutcome(?Uuid $outcomeId, CurriculumProgram $program): CurriculumLearningOutcome
    {
        $outcome = null === $outcomeId ? null : $this->freshEntities->findFreshLockedCurriculumLearningOutcome($outcomeId, LockMode::PESSIMISTIC_WRITE);
        if (!$outcome instanceof CurriculumLearningOutcome || !$outcome->getCurriculumProgram()->getId()->equals($program->getId())) {
            throw new CurriculumDeletionException('Kayıt bu programda yok.');
        }

        return $outcome;
    }

    private function assertActive(CurriculumContentStatus $status): void
    {
        if (CurriculumContentStatus::Active !== $status) {
            throw new CurriculumDeletionException('Arşivlenmiş kayıt silinmez.');
        }
    }

    private function assertNoChildren(int $count, string $message): void
    {
        if ($count > 0) {
            throw new CurriculumDeletionException($message);
        }
    }

    private function count(string $table, string $column, Uuid $id): int
    {
        $allowed = [
            'curriculum_units.curriculum_program_id',
            'curriculum_topics.unit_id',
            'curriculum_topics.parent_id',
            'curriculum_learning_outcomes.topic_id',
            'classroom_courses.curriculum_program_id',
            'question_revision_alignments.curriculum_program_id',
            'question_revision_alignments.curriculum_topic_id',
            'question_revision_alignments.learning_outcome_id',
            'learning_content_outcome_alignments.curriculum_program_id',
            'learning_content_outcome_alignments.curriculum_topic_id',
            'learning_content_outcome_alignments.learning_outcome_id',
        ];
        if (!\in_array($table.'.'.$column, $allowed, true)) {
            throw new CurriculumDeletionException('Kayıt silinemedi.');
        }

        return (int) $this->entityManager->getConnection()->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE %s = :id', $table, $column),
            ['id' => $id->toBinary()],
            ['id' => ParameterType::BINARY],
        );
    }
}

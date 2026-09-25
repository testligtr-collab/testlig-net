<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\CatalogTopic;
use App\Entity\CatalogTopicLesson;
use App\Entity\LearningContent;
use App\Entity\User;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Exception\CatalogException;
use App\Repository\CatalogTopicLessonRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Write path for CatalogTopicLesson placements (navigation only).
 *
 * Lock order: CatalogTopic → LearningContent → Users (actor) → CatalogTopicLesson → Audit.
 * Position conflicts are never silently renumbered.
 */
final class CatalogTopicLessonManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogTopicLessonRepository $lessons,
        private readonly CatalogSlugger $slugger,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(
        User $actor,
        Uuid $catalogTopicId,
        Uuid $learningContentId,
        string $displayTitle,
        ?string $summary,
        int $position,
        string $reasonCode,
        ?string $slugOverride = null,
        ?string $operatorNote = null,
    ): CatalogTopicLesson {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $operatorNote = $this->boundedNote($operatorNote);
        $displayTitle = trim($displayTitle);
        $slug = $slugOverride ? $this->slugger->slugify($slugOverride) : $this->slugger->slugify($displayTitle);
        $actorId = $actor->getId();

        try {
            return $this->em->wrapInTransaction(function () use (
                $actorId,
                $catalogTopicId,
                $learningContentId,
                $displayTitle,
                $summary,
                $position,
                $slug,
                $reasonCode,
                $operatorNote,
            ): CatalogTopicLesson {
                $topic = $this->lockTopic($catalogTopicId);
                $content = $this->lockContent($learningContentId);
                $freshActor = $this->lockActor($actorId);
                $this->assertMayCreate($freshActor);

                $this->assertBindableContent($topic, $content);
                if ($this->lessons->existsSlugForTopic($topic, $slug)) {
                    throw CatalogException::conflict('Bu konu altında aynı kısa adres zaten kullanılıyor.');
                }
                if ($this->lessons->existsPositionForTopic($topic, $position)) {
                    throw CatalogException::conflict('Bu konu altında aynı sıra numarası zaten kullanılıyor.');
                }
                if ($this->lessons->existsContentForTopic($topic, $content)) {
                    throw CatalogException::conflict('Bu öğrenme içeriği bu konuya zaten bağlı.');
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $lesson = CatalogTopicLesson::createDraft(
                    $topic,
                    $content,
                    $slug,
                    $displayTitle,
                    $summary,
                    $position,
                    $freshActor,
                    $now,
                );
                $this->lessons->save($lesson, false);
                $this->audit($freshActor, SecurityAuditAction::CatalogTopicLessonCreated, $lesson, $reasonCode, $this->noteMeta([
                    'old_status' => null,
                    'new_status' => $lesson->getVisibilityStatus()->value,
                ], $operatorNote));
                $this->em->flush();

                return $lesson;
            });
        } catch (UniqueConstraintViolationException) {
            throw CatalogException::conflict('Bu konu için aynı içerik, kısa adres veya sıra zaten kullanılıyor.');
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CatalogException::conflict();
        }
    }

    public function updateDraft(
        User $actor,
        Uuid $lessonId,
        string $displayTitle,
        ?string $summary,
        int $position,
        string $reasonCode,
        ?string $slugOverride = null,
    ): CatalogTopicLesson {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $displayTitle = trim($displayTitle);
        $actorId = $actor->getId();

        try {
            return $this->em->wrapInTransaction(function () use (
                $actorId,
                $lessonId,
                $displayTitle,
                $summary,
                $position,
                $slugOverride,
                $reasonCode,
            ): CatalogTopicLesson {
                $lesson = $this->lockLesson($lessonId);
                $this->lockTopic($lesson->getCatalogTopic()->getId());
                $freshActor = $this->lockActor($actorId);
                $this->assertMayManageDraft($freshActor, $lesson);

                $slug = $slugOverride
                    ? $this->slugger->slugify($slugOverride)
                    : $this->slugger->slugify($displayTitle);
                $topic = $lesson->getCatalogTopic();
                if ($this->lessons->existsSlugForTopic($topic, $slug, $lesson->getId())) {
                    throw CatalogException::conflict('Bu konu altında aynı kısa adres zaten kullanılıyor.');
                }
                if ($this->lessons->existsPositionForTopic($topic, $position, $lesson->getId())) {
                    throw CatalogException::conflict('Bu konu altında aynı sıra numarası zaten kullanılıyor.');
                }

                $oldStatus = $lesson->getVisibilityStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $lesson->updateDetails($slug, $displayTitle, $summary, $position, $now);
                $this->audit($freshActor, SecurityAuditAction::CatalogTopicLessonUpdated, $lesson, $reasonCode, [
                    'old_status' => $oldStatus,
                    'new_status' => $lesson->getVisibilityStatus()->value,
                ]);
                $this->em->flush();

                return $lesson;
            });
        } catch (UniqueConstraintViolationException) {
            throw CatalogException::conflict('Bu konu için aynı içerik, kısa adres veya sıra zaten kullanılıyor.');
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CatalogException::conflict();
        }
    }

    public function publish(User $actor, Uuid $lessonId, string $reasonCode, ?string $operatorNote = null): CatalogTopicLesson
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $operatorNote = $this->boundedNote($operatorNote);
        $actorId = $actor->getId();

        try {
            return $this->em->wrapInTransaction(function () use ($actorId, $lessonId, $reasonCode, $operatorNote): CatalogTopicLesson {
                $lesson = $this->lockLesson($lessonId);
                $this->lockTopic($lesson->getCatalogTopic()->getId());
                $content = $this->lockContent($lesson->getLearningContent()->getId());
                $freshActor = $this->lockActor($actorId);
                $this->assertMayPublish($freshActor);

                if (LearningContentStatus::Published !== $content->getStatus()) {
                    throw CatalogException::invalidTransition(
                        'Yerleşim, bağlı öğrenme içeriği yayımlanmadan yayımlanamaz.',
                    );
                }
                $publishedRevision = $content->getPublishedRevision();
                if (null === $publishedRevision || !$publishedRevision->isSealed()) {
                    throw CatalogException::invalidTransition(
                        'Yerleşim, geçerli yayımlanmış revision olmadan yayımlanamaz.',
                    );
                }

                $oldStatus = $lesson->getVisibilityStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $lesson->publish($now);
                $this->audit($freshActor, SecurityAuditAction::CatalogTopicLessonPublished, $lesson, $reasonCode, $this->noteMeta([
                    'old_status' => $oldStatus,
                    'new_status' => $lesson->getVisibilityStatus()->value,
                ], $operatorNote));
                $this->em->flush();

                return $lesson;
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CatalogException::conflict();
        }
    }

    public function archive(User $actor, Uuid $lessonId, string $reasonCode, ?string $operatorNote = null): CatalogTopicLesson
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $operatorNote = $this->boundedNote($operatorNote);
        $actorId = $actor->getId();

        try {
            return $this->em->wrapInTransaction(function () use ($actorId, $lessonId, $reasonCode, $operatorNote): CatalogTopicLesson {
                $lesson = $this->lockLesson($lessonId);
                $this->lockTopic($lesson->getCatalogTopic()->getId());
                $freshActor = $this->lockActor($actorId);
                $this->assertMayArchive($freshActor);

                $oldStatus = $lesson->getVisibilityStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $lesson->archive($now);
                $this->audit($freshActor, SecurityAuditAction::CatalogTopicLessonArchived, $lesson, $reasonCode, $this->noteMeta([
                    'old_status' => $oldStatus,
                    'new_status' => $lesson->getVisibilityStatus()->value,
                ], $operatorNote));
                $this->em->flush();

                return $lesson;
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CatalogException::conflict();
        }
    }

    private function assertBindableContent(CatalogTopic $topic, LearningContent $content): void
    {
        if (LearningContentScope::Platform !== $content->getScope()) {
            throw CatalogException::invalidInput('Yalnız platform öğrenme içeriği kataloğa bağlanabilir.');
        }
        if (LearningContentStatus::Archived === $content->getStatus()) {
            throw CatalogException::invalidInput('Arşivlenmiş içerik bağlanamaz.');
        }

        $catalogSubject = $topic->getUnit()->getSubject();
        $canonical = $catalogSubject->getCanonicalSubject();
        if (null !== $canonical && !$canonical->getId()->equals($content->getSubject()->getId())) {
            throw CatalogException::invalidInput(
                'Öğrenme içeriği subject eşlemesi katalog dersinin canonical subject’i ile uyuşmuyor.',
            );
        }
    }

    private function assertMayCreate(User $actor): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw CatalogException::unauthorized();
        }
        if ($this->hasAnyRole($actor, [
            UserRole::SuperAdmin,
            UserRole::Admin,
            UserRole::HeadTeacher,
            UserRole::ExpertTeacher,
            UserRole::Teacher,
        ])) {
            return;
        }

        throw CatalogException::unauthorized();
    }

    private function assertMayManageDraft(User $actor, CatalogTopicLesson $lesson): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw CatalogException::unauthorized();
        }
        if ($this->hasAnyRole($actor, [
            UserRole::SuperAdmin,
            UserRole::Admin,
            UserRole::HeadTeacher,
            UserRole::ExpertTeacher,
        ])) {
            return;
        }
        if ($this->hasAnyRole($actor, [UserRole::Teacher])
            && $lesson->isDraft()
            && null !== $lesson->getCreatedBy()
            && $lesson->getCreatedBy()->getId()->equals($actor->getId())
        ) {
            return;
        }

        throw CatalogException::unauthorized();
    }

    private function assertMayPublish(User $actor): void
    {
        if (!$this->activeVerifiedUserPolicy->isActiveAndVerified($actor)) {
            throw CatalogException::unauthorized();
        }
        if ($this->hasAnyRole($actor, [
            UserRole::SuperAdmin,
            UserRole::Admin,
            UserRole::HeadTeacher,
            UserRole::ExpertTeacher,
        ])) {
            return;
        }

        throw CatalogException::unauthorized();
    }

    private function assertMayArchive(User $actor): void
    {
        $this->assertMayPublish($actor);
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

    private function lockTopic(Uuid $topicId): CatalogTopic
    {
        $topic = $this->em->createQueryBuilder()
            ->select('t', 'u', 's', 'cs')
            ->from(CatalogTopic::class, 't')
            ->innerJoin('t.unit', 'u')
            ->innerJoin('u.subject', 's')
            ->leftJoin('s.canonicalSubject', 'cs')
            ->andWhere('t.id = :id')
            ->setParameter('id', $topicId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        if (!$topic instanceof CatalogTopic) {
            throw CatalogException::notFound();
        }

        return $topic;
    }

    private function lockContent(Uuid $contentId): LearningContent
    {
        $content = $this->em->createQueryBuilder()
            ->select('c')
            ->from(LearningContent::class, 'c')
            ->andWhere('c.id = :id')
            ->setParameter('id', $contentId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        if (!$content instanceof LearningContent) {
            throw CatalogException::notFound();
        }

        return $content;
    }

    private function lockLesson(Uuid $lessonId): CatalogTopicLesson
    {
        $lesson = $this->em->createQueryBuilder()
            ->select('l')
            ->from(CatalogTopicLesson::class, 'l')
            ->andWhere('l.id = :id')
            ->setParameter('id', $lessonId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        if (!$lesson instanceof CatalogTopicLesson) {
            throw CatalogException::notFound();
        }

        return $lesson;
    }

    private function lockActor(Uuid $actorId): User
    {
        $user = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->andWhere('u.id = :id')
            ->setParameter('id', $actorId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_READ)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        if (!$user instanceof User) {
            throw CatalogException::unauthorized();
        }

        return $user;
    }

    /**
     * @param array<string, scalar|null> $extra
     */
    private function audit(
        User $actor,
        SecurityAuditAction $action,
        CatalogTopicLesson $lesson,
        string $reasonCode,
        array $extra = [],
    ): void {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: array_merge([
                'source' => 'catalog_topic_lesson_manager',
                'reason_code' => $reasonCode,
                'catalog_topic_lesson_id' => $lesson->getId()->toRfc4122(),
                'catalog_topic_id' => $lesson->getCatalogTopic()->getId()->toRfc4122(),
                'content_id' => $lesson->getLearningContent()->getId()->toRfc4122(),
                'position' => $lesson->getPosition(),
            ], $extra),
            captureRequestHashes: false,
        ), false);
    }

    private function normalizeReasonCode(string $reasonCode): string
    {
        $reasonCode = trim($reasonCode);
        if ('' === $reasonCode || \strlen($reasonCode) > 64) {
            throw CatalogException::invalidInput('reason_code geçersiz.');
        }

        return $reasonCode;
    }

    /**
     * @param array<string, scalar|null> $extra
     *
     * @return array<string, scalar|null>
     */
    private function noteMeta(array $extra, ?string $operatorNote): array
    {
        if (null !== $operatorNote && '' !== $operatorNote) {
            $extra['operator_note'] = $operatorNote;
        }

        return $extra;
    }

    private function boundedNote(?string $operatorNote): ?string
    {
        if (null === $operatorNote) {
            return null;
        }
        $operatorNote = trim($operatorNote);
        if ('' === $operatorNote) {
            return null;
        }
        if (mb_strlen($operatorNote) > 500) {
            return mb_substr($operatorNote, 0, 500);
        }

        return $operatorNote;
    }
}

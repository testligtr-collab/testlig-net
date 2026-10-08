<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SecurityAuditContext;
use App\Entity\Assessment;
use App\Entity\CatalogTopic;
use App\Entity\CatalogTopicAssessment;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\CatalogPublicationStatus;
use App\Enum\SecurityAuditAction;
use App\Enum\SecurityAuditActorType;
use App\Enum\SecurityAuditOutcome;
use App\Enum\UserRole;
use App\Exception\CatalogException;
use App\Repository\CatalogTopicAssessmentRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Write path for CatalogTopicAssessment placements (navigation only).
 *
 * Lock order: CatalogTopic → Assessment → Users (actor) → CatalogTopicAssessment → Audit.
 * Position conflicts are never silently renumbered.
 */
final class CatalogTopicAssessmentManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogTopicAssessmentRepository $placements,
        private readonly CatalogSlugger $slugger,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(
        User $actor,
        Uuid $catalogTopicId,
        Uuid $assessmentId,
        string $displayTitle,
        ?string $summary,
        int $position,
        string $reasonCode,
        ?string $slugOverride = null,
        ?string $operatorNote = null,
    ): CatalogTopicAssessment {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $operatorNote = $this->boundedNote($operatorNote);
        $displayTitle = trim($displayTitle);
        $slug = $slugOverride ? $this->slugger->slugify($slugOverride) : $this->slugger->slugify($displayTitle);
        $actorId = $actor->getId();

        try {
            return $this->em->wrapInTransaction(function () use (
                $actorId,
                $catalogTopicId,
                $assessmentId,
                $displayTitle,
                $summary,
                $position,
                $slug,
                $reasonCode,
                $operatorNote,
            ): CatalogTopicAssessment {
                $topic = $this->lockTopic($catalogTopicId);
                $assessment = $this->lockAssessment($assessmentId);
                $freshActor = $this->lockActor($actorId);
                $this->assertMayCreate($freshActor);

                $this->assertBindableAssessment($topic, $assessment);
                if ($this->placements->existsSlugForTopic($topic, $slug)) {
                    throw CatalogException::conflict('Bu konu altında aynı kısa adres zaten kullanılıyor.');
                }
                if ($this->placements->existsPositionForTopic($topic, $position)) {
                    throw CatalogException::conflict('Bu konu altında aynı sıra numarası zaten kullanılıyor.');
                }
                if ($this->placements->existsAssessmentForTopic($topic, $assessment)) {
                    throw CatalogException::conflict('Bu değerlendirme bu konuya zaten bağlı.');
                }

                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $placement = CatalogTopicAssessment::createDraft(
                    $topic,
                    $assessment,
                    $slug,
                    $displayTitle,
                    $summary,
                    $position,
                    $freshActor,
                    $now,
                );
                $this->placements->save($placement, false);
                $this->audit($freshActor, SecurityAuditAction::CatalogTopicAssessmentCreated, $placement, $reasonCode, $this->noteMeta([
                    'old_status' => null,
                    'new_status' => $placement->getVisibilityStatus()->value,
                ], $operatorNote));
                $this->em->flush();

                return $placement;
            });
        } catch (UniqueConstraintViolationException) {
            throw CatalogException::conflict('Bu konu için aynı değerlendirme, kısa adres veya sıra zaten kullanılıyor.');
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CatalogException::conflict();
        }
    }

    public function updateDraft(
        User $actor,
        Uuid $placementId,
        string $displayTitle,
        ?string $summary,
        int $position,
        string $reasonCode,
        ?string $slugOverride = null,
    ): CatalogTopicAssessment {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $displayTitle = trim($displayTitle);
        $actorId = $actor->getId();

        try {
            return $this->em->wrapInTransaction(function () use (
                $actorId,
                $placementId,
                $displayTitle,
                $summary,
                $position,
                $slugOverride,
                $reasonCode,
            ): CatalogTopicAssessment {
                $placementPreview = $this->placements->findOneById($placementId);
                if (!$placementPreview instanceof CatalogTopicAssessment) {
                    throw CatalogException::notFound();
                }
                $topic = $this->lockTopic($placementPreview->getCatalogTopic()->getId());
                $this->lockAssessment($placementPreview->getAssessment()->getId());
                $freshActor = $this->lockActor($actorId);
                $placement = $this->lockPlacement($placementId);
                $this->assertMayManageDraft($freshActor, $placement);

                $slug = $slugOverride
                    ? $this->slugger->slugify($slugOverride)
                    : $this->slugger->slugify($displayTitle);
                if ($this->placements->existsSlugForTopic($topic, $slug, $placement->getId())) {
                    throw CatalogException::conflict('Bu konu altında aynı kısa adres zaten kullanılıyor.');
                }
                if ($this->placements->existsPositionForTopic($topic, $position, $placement->getId())) {
                    throw CatalogException::conflict('Bu konu altında aynı sıra numarası zaten kullanılıyor.');
                }

                $oldStatus = $placement->getVisibilityStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $placement->updateDetails($slug, $displayTitle, $summary, $position, $now);
                $this->audit($freshActor, SecurityAuditAction::CatalogTopicAssessmentUpdated, $placement, $reasonCode, [
                    'old_status' => $oldStatus,
                    'new_status' => $placement->getVisibilityStatus()->value,
                ]);
                $this->em->flush();

                return $placement;
            });
        } catch (UniqueConstraintViolationException) {
            throw CatalogException::conflict('Bu konu için aynı değerlendirme, kısa adres veya sıra zaten kullanılıyor.');
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CatalogException::conflict();
        }
    }

    public function publish(User $actor, Uuid $placementId, string $reasonCode, ?string $operatorNote = null): CatalogTopicAssessment
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $operatorNote = $this->boundedNote($operatorNote);
        $actorId = $actor->getId();

        try {
            return $this->em->wrapInTransaction(function () use ($actorId, $placementId, $reasonCode, $operatorNote): CatalogTopicAssessment {
                $placementPreview = $this->placements->findOneById($placementId);
                if (!$placementPreview instanceof CatalogTopicAssessment) {
                    throw CatalogException::notFound();
                }
                $topic = $this->lockTopic($placementPreview->getCatalogTopic()->getId());
                $assessment = $this->lockAssessment($placementPreview->getAssessment()->getId());
                $freshActor = $this->lockActor($actorId);
                $placement = $this->lockPlacement($placementId);
                $this->assertMayPublish($freshActor);

                $this->assertPublishedCatalogPath($topic);
                $this->assertBindableAssessment($topic, $assessment);

                if (AssessmentStatus::Published !== $assessment->getStatus()) {
                    throw CatalogException::invalidTransition(
                        'Yerleşim, bağlı değerlendirme yayımlanmadan yayımlanamaz.',
                    );
                }
                $publishedRevision = $assessment->getPublishedRevision();
                if (null === $publishedRevision || !$publishedRevision->isSealed()) {
                    throw CatalogException::invalidTransition(
                        'Yerleşim, geçerli yayımlanmış revision olmadan yayımlanamaz.',
                    );
                }

                $oldStatus = $placement->getVisibilityStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $placement->publish($now);
                $this->audit($freshActor, SecurityAuditAction::CatalogTopicAssessmentPublished, $placement, $reasonCode, $this->noteMeta([
                    'old_status' => $oldStatus,
                    'new_status' => $placement->getVisibilityStatus()->value,
                ], $operatorNote));
                $this->em->flush();

                return $placement;
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CatalogException::conflict();
        }
    }

    public function archive(User $actor, Uuid $placementId, string $reasonCode, ?string $operatorNote = null): CatalogTopicAssessment
    {
        $reasonCode = $this->normalizeReasonCode($reasonCode);
        $operatorNote = $this->boundedNote($operatorNote);
        $actorId = $actor->getId();

        try {
            return $this->em->wrapInTransaction(function () use ($actorId, $placementId, $reasonCode, $operatorNote): CatalogTopicAssessment {
                $placementPreview = $this->placements->findOneById($placementId);
                if (!$placementPreview instanceof CatalogTopicAssessment) {
                    throw CatalogException::notFound();
                }
                $this->lockTopic($placementPreview->getCatalogTopic()->getId());
                $this->lockAssessment($placementPreview->getAssessment()->getId());
                $freshActor = $this->lockActor($actorId);
                $placement = $this->lockPlacement($placementId);
                $this->assertMayArchive($freshActor);

                $oldStatus = $placement->getVisibilityStatus()->value;
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                $placement->archive($now);
                $this->audit($freshActor, SecurityAuditAction::CatalogTopicAssessmentArchived, $placement, $reasonCode, $this->noteMeta([
                    'old_status' => $oldStatus,
                    'new_status' => $placement->getVisibilityStatus()->value,
                ], $operatorNote));
                $this->em->flush();

                return $placement;
            });
        } catch (DeadlockException|LockWaitTimeoutException) {
            throw CatalogException::conflict();
        }
    }

    private function assertBindableAssessment(CatalogTopic $topic, Assessment $assessment): void
    {
        if (AssessmentScope::Platform !== $assessment->getScope()) {
            throw CatalogException::invalidInput('Yalnız platform değerlendirmesi kataloğa bağlanabilir.');
        }
        if (null !== $assessment->getInstitution()) {
            throw CatalogException::invalidInput('Kurum değerlendirmesi kataloğa bağlanamaz.');
        }
        if (AssessmentStatus::Archived === $assessment->getStatus()) {
            throw CatalogException::invalidInput('Arşivlenmiş değerlendirme bağlanamaz.');
        }

        $catalogSubject = $topic->getUnit()->getSubject();
        if ($catalogSubject->getGradeLevel() !== $assessment->getGradeLevel()) {
            throw CatalogException::invalidInput(
                'Değerlendirme sınıf düzeyi katalog dersinin sınıf düzeyi ile uyuşmuyor.',
            );
        }

        $canonical = $catalogSubject->getCanonicalSubject();
        if (!$canonical instanceof Subject) {
            throw CatalogException::invalidInput(
                'Katalog dersinin canonical subject eşlemesi olmadan değerlendirme bağlanamaz.',
            );
        }
        $assessmentSubject = $assessment->getSubject();
        if (!$assessmentSubject instanceof Subject) {
            throw CatalogException::invalidInput(
                'Değerlendirme subject eşlemesi katalog dersinin canonical subject’i ile uyuşmuyor.',
            );
        }
        if (!$canonical->getId()->equals($assessmentSubject->getId())) {
            throw CatalogException::invalidInput(
                'Değerlendirme subject eşlemesi katalog dersinin canonical subject’i ile uyuşmuyor.',
            );
        }
    }

    private function assertPublishedCatalogPath(CatalogTopic $topic): void
    {
        if (CatalogPublicationStatus::Published !== $topic->getStatus()) {
            throw CatalogException::invalidTransition('Yerleşim, yayımlanmamış konu altında yayımlanamaz.');
        }
        $unit = $topic->getUnit();
        if (CatalogPublicationStatus::Published !== $unit->getStatus()) {
            throw CatalogException::invalidTransition('Yerleşim, yayımlanmamış ünite altında yayımlanamaz.');
        }
        $subject = $unit->getSubject();
        if (CatalogPublicationStatus::Published !== $subject->getStatus()) {
            throw CatalogException::invalidTransition('Yerleşim, yayımlanmamış ders altında yayımlanamaz.');
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

    private function assertMayManageDraft(User $actor, CatalogTopicAssessment $placement): void
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
            && $placement->isDraft()
            && null !== $placement->getCreatedBy()
            && $placement->getCreatedBy()->getId()->equals($actor->getId())
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

    private function lockAssessment(Uuid $assessmentId): Assessment
    {
        $assessment = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->andWhere('a.id = :id')
            ->setParameter('id', $assessmentId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        if (!$assessment instanceof Assessment) {
            throw CatalogException::notFound();
        }

        return $assessment;
    }

    private function lockPlacement(Uuid $placementId): CatalogTopicAssessment
    {
        $placement = $this->em->createQueryBuilder()
            ->select('p')
            ->from(CatalogTopicAssessment::class, 'p')
            ->andWhere('p.id = :id')
            ->setParameter('id', $placementId, 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        if (!$placement instanceof CatalogTopicAssessment) {
            throw CatalogException::notFound();
        }

        return $placement;
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
        CatalogTopicAssessment $placement,
        string $reasonCode,
        array $extra = [],
    ): void {
        $this->auditRecorder->record(new SecurityAuditContext(
            action: $action,
            actorType: SecurityAuditActorType::User,
            outcome: SecurityAuditOutcome::Success,
            actorUser: $actor,
            metadata: array_merge([
                'source' => 'catalog_topic_assessment_manager',
                'reason_code' => $reasonCode,
                'catalog_topic_assessment_id' => $placement->getId()->toRfc4122(),
                'catalog_topic_id' => $placement->getCatalogTopic()->getId()->toRfc4122(),
                'assessment_id' => $placement->getAssessment()->getId()->toRfc4122(),
                'position' => $placement->getPosition(),
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

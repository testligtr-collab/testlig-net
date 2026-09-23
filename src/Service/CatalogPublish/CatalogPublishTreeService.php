<?php

declare(strict_types=1);

namespace App\Service\CatalogPublish;

use App\Entity\CatalogSubject;
use App\Entity\CatalogTopic;
use App\Entity\CatalogUnit;
use App\Enum\CatalogPublicationStatus;
use App\Enum\GradeLevel;
use App\Exception\CatalogException;
use App\Repository\CatalogSubjectRepository;
use App\Repository\CatalogTopicRepository;
use App\Repository\CatalogUnitRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Atomic bottom-up publish of one MEB/TYMM catalog tree (topics → units → subject).
 * Uses entity publish rules; no raw SQL updates, archive, or content mutation.
 */
final class CatalogPublishTreeService
{
    private const LOCK_KEY = 'app.catalog.publish-tree';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogSubjectRepository $subjects,
        private readonly CatalogUnitRepository $units,
        private readonly CatalogTopicRepository $topics,
        private readonly ClockInterface $clock,
        private readonly LockFactory $lockFactory,
        private readonly ?\Closure $afterTopicsHook = null,
    ) {
    }

    public function publish(
        string $sourceVersion,
        string $subjectCode,
        int $expectedSubjects,
        int $expectedUnits,
        int $expectedTopics,
        bool $apply,
        GradeLevel $requiredGrade = GradeLevel::Grade1,
    ): CatalogPublishTreeResult {
        $sourceVersion = trim($sourceVersion);
        $subjectCode = trim($subjectCode);
        if ('' === $sourceVersion || '' === $subjectCode) {
            throw CatalogException::invalidInput('source-version ve subject-code zorunludur.');
        }
        if ($expectedSubjects < 1 || $expectedUnits < 1 || $expectedTopics < 1) {
            throw CatalogException::invalidInput('Beklenen sayılar pozitif olmalıdır.');
        }

        $result = new CatalogPublishTreeResult();
        $result->dryRun = !$apply;
        $result->line(\sprintf(
            'Target: version=%s subject_code=%s grade=%d expected=%d/%d/%d',
            $sourceVersion,
            $subjectCode,
            $requiredGrade->value,
            $expectedSubjects,
            $expectedUnits,
            $expectedTopics,
        ));

        $lock = $this->lockFactory->createLock(self::LOCK_KEY, 300.0);
        if (!$lock->acquire()) {
            throw CatalogException::conflict('Katalog yayın kilidi alınamadı; başka bir publish çalışıyor olabilir.');
        }

        try {
            $tree = $this->loadAndValidateTree(
                $sourceVersion,
                $subjectCode,
                $expectedSubjects,
                $expectedUnits,
                $expectedTopics,
                $requiredGrade,
                $result,
            );

            if ($result->noop) {
                $result->line('Tree already fully published — no-op.');

                return $result;
            }

            if (!$apply) {
                $this->plan($tree, $result);

                return $result;
            }

            $this->em->wrapInTransaction(function () use ($tree, $result): void {
                $this->applyPublish($tree, $result);
            });
            $result->applied = true;
            $result->dryRun = false;
        } finally {
            $lock->release();
        }

        return $result;
    }

    /**
     * @return array{subject: CatalogSubject, units: list<CatalogUnit>, topics: list<CatalogTopic>}
     */
    private function loadAndValidateTree(
        string $sourceVersion,
        string $subjectCode,
        int $expectedSubjects,
        int $expectedUnits,
        int $expectedTopics,
        GradeLevel $requiredGrade,
        CatalogPublishTreeResult $result,
    ): array {
        $subject = $this->subjects->findOneBySourceIdentity($sourceVersion, $subjectCode, 1);
        if (!$subject instanceof CatalogSubject) {
            throw CatalogException::notFound();
        }
        if ($subject->getGradeLevel() !== $requiredGrade) {
            throw CatalogException::invalidInput(\sprintf(
                'Subject grade_level=%d; beklenen %d.',
                $subject->getGradeLevel()->value,
                $requiredGrade->value,
            ));
        }
        if (CatalogPublicationStatus::Archived === $subject->getStatus()) {
            throw CatalogException::invalidInput('Hedef subject archived; yayınlanamaz.');
        }
        if ($sourceVersion !== $subject->getSourceVersion() || $subjectCode !== $subject->getSourceCode()) {
            throw CatalogException::invalidInput('Subject kaynak kimliği tutarsız.');
        }

        $units = $this->units->findBySubjectOrdered($subject);
        if (\count($units) !== $expectedUnits) {
            throw CatalogException::invalidInput(\sprintf(
                'Unit sayısı %d; beklenen %d.',
                \count($units),
                $expectedUnits,
            ));
        }

        $topics = [];
        $seenUnitKeys = [];
        foreach ($units as $unit) {
            if ($unit->getSubject()->getId()->toRfc4122() !== $subject->getId()->toRfc4122()) {
                throw CatalogException::invalidInput('Unit başka subject’e bağlı.');
            }
            if ($sourceVersion !== $unit->getSourceVersion()) {
                throw CatalogException::invalidInput('Unit source_version tutarsız: '.$unit->getSlug());
            }
            if (null === $unit->getSourceCode()) {
                throw CatalogException::invalidInput('Unit source_code eksik: '.$unit->getSlug());
            }
            if (CatalogPublicationStatus::Archived === $unit->getStatus()) {
                throw CatalogException::invalidInput('Unit archived; yayınlanamaz: '.$unit->getSlug());
            }
            $unitKey = $unit->getSourceCode().'@'.$unit->getSourceOccurrence();
            if (isset($seenUnitKeys[$unitKey])) {
                throw CatalogException::invalidInput('Yinelenen unit kaynak kimliği: '.$unitKey);
            }
            $seenUnitKeys[$unitKey] = true;

            $unitTopics = $this->topics->findByUnitOrdered($unit);
            foreach ($unitTopics as $topic) {
                if ($topic->getUnit()->getId()->toRfc4122() !== $unit->getId()->toRfc4122()) {
                    throw CatalogException::invalidInput('Topic başka unit’e bağlı.');
                }
                if ($sourceVersion !== $topic->getSourceVersion()) {
                    throw CatalogException::invalidInput('Topic source_version tutarsız: '.$topic->getSlug());
                }
                if (null === $topic->getSourceCode()) {
                    throw CatalogException::invalidInput('Topic source_code eksik: '.$topic->getSlug());
                }
                if (CatalogPublicationStatus::Archived === $topic->getStatus()) {
                    throw CatalogException::invalidInput('Topic archived; yayınlanamaz: '.$topic->getSlug());
                }
                $topics[] = $topic;
            }
        }

        if (\count($topics) !== $expectedTopics) {
            throw CatalogException::invalidInput(\sprintf(
                'Topic sayısı %d; beklenen %d.',
                \count($topics),
                $expectedTopics,
            ));
        }

        $seenTopicKeys = [];
        foreach ($topics as $topic) {
            $topicKey = (string) $topic->getSourceCode().'@'.$topic->getSourceOccurrence();
            if (isset($seenTopicKeys[$topicKey])) {
                throw CatalogException::invalidInput('Yinelenen topic kaynak kimliği: '.$topicKey);
            }
            $seenTopicKeys[$topicKey] = true;
        }

        // Exactly one subject loaded by identity; enforce expectedSubjects match.
        if (1 !== $expectedSubjects) {
            // Identity lookup returns at most one row; any other expectation is a guard mismatch.
            throw CatalogException::invalidInput(\sprintf(
                'Subject sayısı 1; beklenen %d.',
                $expectedSubjects,
            ));
        }
        $result->subjectsFound = 1;
        $result->unitsFound = \count($units);
        $result->topicsFound = \count($topics);

        if (
            CatalogPublicationStatus::Published === $subject->getStatus()
            && $this->allPublished($units)
            && $this->allPublished($topics)
        ) {
            $result->noop = true;
            $result->subjectsAlreadyPublished = 1;
            $result->unitsAlreadyPublished = \count($units);
            $result->topicsAlreadyPublished = \count($topics);
            $result->skipped = 1 + \count($units) + \count($topics);
        }

        return [
            'subject' => $subject,
            'units' => $units,
            'topics' => $topics,
        ];
    }

    /**
     * @param list<CatalogUnit>|list<CatalogTopic> $entities
     */
    private function allPublished(array $entities): bool
    {
        foreach ($entities as $entity) {
            if (CatalogPublicationStatus::Published !== $entity->getStatus()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{subject: CatalogSubject, units: list<CatalogUnit>, topics: list<CatalogTopic>} $tree
     */
    private function plan(array $tree, CatalogPublishTreeResult $result): void
    {
        foreach ($tree['topics'] as $topic) {
            if (CatalogPublicationStatus::Draft === $topic->getStatus()) {
                ++$result->topicsToPublish;
                $result->line('[publish] topic '.$topic->getSourceCode().'@'.$topic->getSourceOccurrence());
            } elseif (CatalogPublicationStatus::Published === $topic->getStatus()) {
                ++$result->topicsAlreadyPublished;
                ++$result->skipped;
                $result->line('[skip] topic already published '.$topic->getSourceCode());
            } else {
                throw CatalogException::invalidInput('Topic yayınlanamaz durumda: '.$topic->getSlug());
            }
        }
        foreach ($tree['units'] as $unit) {
            if (CatalogPublicationStatus::Draft === $unit->getStatus()) {
                ++$result->unitsToPublish;
                $result->line('[publish] unit '.$unit->getSourceCode().'@'.$unit->getSourceOccurrence());
            } elseif (CatalogPublicationStatus::Published === $unit->getStatus()) {
                ++$result->unitsAlreadyPublished;
                ++$result->skipped;
                $result->line('[skip] unit already published '.$unit->getSourceCode().'@'.$unit->getSourceOccurrence());
            } else {
                throw CatalogException::invalidInput('Unit yayınlanamaz durumda: '.$unit->getSlug());
            }
        }
        $subject = $tree['subject'];
        if (CatalogPublicationStatus::Draft === $subject->getStatus()) {
            ++$result->subjectsToPublish;
            $result->line('[publish] subject '.$subject->getSourceCode());
        } elseif (CatalogPublicationStatus::Published === $subject->getStatus()) {
            ++$result->subjectsAlreadyPublished;
            ++$result->skipped;
            $result->line('[skip] subject already published '.$subject->getSourceCode());
        } else {
            throw CatalogException::invalidInput('Subject yayınlanamaz durumda.');
        }
    }

    /**
     * @param array{subject: CatalogSubject, units: list<CatalogUnit>, topics: list<CatalogTopic>} $tree
     */
    private function applyPublish(array $tree, CatalogPublishTreeResult $result): void
    {
        $now = $this->clock->now();

        // Bottom-up: topics → units → subject. Re-lock fresh rows inside the TX.
        foreach ($tree['topics'] as $topic) {
            $locked = $this->lockTopic($topic->getId());
            if (CatalogPublicationStatus::Archived === $locked->getStatus()) {
                throw CatalogException::invalidInput('Topic archived during publish: '.$locked->getSlug());
            }
            if (CatalogPublicationStatus::Draft === $locked->getStatus()) {
                $locked->publish($now);
                ++$result->published;
                ++$result->topicsToPublish;
                $result->line('[publish] topic '.$locked->getSourceCode());
            } else {
                ++$result->skipped;
                ++$result->topicsAlreadyPublished;
                $result->line('[skip] topic '.$locked->getSourceCode());
            }
        }

        $this->afterTopicsPublished();

        foreach ($tree['units'] as $unit) {
            $locked = $this->lockUnit($unit->getId());
            if (CatalogPublicationStatus::Archived === $locked->getStatus()) {
                throw CatalogException::invalidInput('Unit archived during publish: '.$locked->getSlug());
            }
            if (CatalogPublicationStatus::Draft === $locked->getStatus()) {
                $locked->publish($now);
                ++$result->published;
                ++$result->unitsToPublish;
                $result->line('[publish] unit '.$locked->getSourceCode().'@'.$locked->getSourceOccurrence());
            } else {
                ++$result->skipped;
                ++$result->unitsAlreadyPublished;
                $result->line('[skip] unit '.$locked->getSourceCode().'@'.$locked->getSourceOccurrence());
            }
        }

        $lockedSubject = $this->lockSubject($tree['subject']->getId());
        if (CatalogPublicationStatus::Archived === $lockedSubject->getStatus()) {
            throw CatalogException::invalidInput('Subject archived during publish.');
        }
        if (CatalogPublicationStatus::Draft === $lockedSubject->getStatus()) {
            $lockedSubject->publish($now);
            ++$result->published;
            ++$result->subjectsToPublish;
            $result->line('[publish] subject '.$lockedSubject->getSourceCode());
        } else {
            ++$result->skipped;
            ++$result->subjectsAlreadyPublished;
            $result->line('[skip] subject '.$lockedSubject->getSourceCode());
        }

        $this->em->flush();
    }

    private function afterTopicsPublished(): void
    {
        if (null !== $this->afterTopicsHook) {
            ($this->afterTopicsHook)();
        }
    }

    private function lockSubject(Uuid $id): CatalogSubject
    {
        $query = $this->em->createQueryBuilder()
            ->select('s')
            ->from(CatalogSubject::class, 's')
            ->andWhere('s.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);
        $subject = $query->getOneOrNullResult();
        if (!$subject instanceof CatalogSubject) {
            throw CatalogException::notFound();
        }

        return $subject;
    }

    private function lockUnit(Uuid $id): CatalogUnit
    {
        $query = $this->em->createQueryBuilder()
            ->select('u', 's')
            ->from(CatalogUnit::class, 'u')
            ->innerJoin('u.subject', 's')
            ->andWhere('u.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);
        $unit = $query->getOneOrNullResult();
        if (!$unit instanceof CatalogUnit) {
            throw CatalogException::notFound();
        }

        return $unit;
    }

    private function lockTopic(Uuid $id): CatalogTopic
    {
        $query = $this->em->createQueryBuilder()
            ->select('t', 'u', 's')
            ->from(CatalogTopic::class, 't')
            ->innerJoin('t.unit', 'u')
            ->innerJoin('u.subject', 's')
            ->andWhere('t.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);
        $topic = $query->getOneOrNullResult();
        if (!$topic instanceof CatalogTopic) {
            throw CatalogException::notFound();
        }

        return $topic;
    }
}

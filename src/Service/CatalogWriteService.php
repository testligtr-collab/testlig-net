<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CatalogSubject;
use App\Entity\CatalogTopic;
use App\Entity\CatalogUnit;
use App\Enum\GradeLevel;
use App\Exception\CatalogException;
use App\Repository\CatalogSubjectRepository;
use App\Repository\CatalogTopicRepository;
use App\Repository\CatalogUnitRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Admin write path for the student course catalog.
 */
final class CatalogWriteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogSubjectRepository $subjects,
        private readonly CatalogUnitRepository $units,
        private readonly CatalogTopicRepository $topics,
        private readonly CatalogSlugger $slugger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createSubject(
        GradeLevel $grade,
        string $name,
        ?string $description,
        int $position,
        ?string $slugOverride = null,
    ): CatalogSubject {
        $name = trim($name);
        $slug = $slugOverride ? $this->slugger->slugify($slugOverride) : $this->slugger->slugify($name);

        return $this->em->wrapInTransaction(function () use ($grade, $name, $description, $position, $slug): CatalogSubject {
            if ($this->subjects->existsSlugForGrade($grade, $slug)) {
                throw CatalogException::conflict('Bu sınıf için aynı kısa adres zaten kullanılıyor.');
            }
            $subject = CatalogSubject::createDraft($grade, $name, $slug, $description, $position, $this->clock->now());
            try {
                $this->subjects->save($subject);
            } catch (UniqueConstraintViolationException) {
                throw CatalogException::conflict('Bu sınıf için aynı kısa adres zaten kullanılıyor.');
            }

            return $subject;
        });
    }

    public function updateSubject(
        Uuid $subjectId,
        string $name,
        ?string $description,
        int $position,
        ?string $slugOverride = null,
    ): CatalogSubject {
        $name = trim($name);

        return $this->em->wrapInTransaction(function () use ($subjectId, $name, $description, $position, $slugOverride): CatalogSubject {
            $subject = $this->lockSubject($subjectId);
            $slug = $slugOverride ? $this->slugger->slugify($slugOverride) : $this->slugger->slugify($name);
            if ($this->subjects->existsSlugForGrade($subject->getGradeLevel(), $slug, $subject->getId())) {
                throw CatalogException::conflict('Bu sınıf için aynı kısa adres zaten kullanılıyor.');
            }
            $subject->updateDetails($name, $slug, $description, $position, $this->clock->now());
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                throw CatalogException::conflict('Bu sınıf için aynı kısa adres zaten kullanılıyor.');
            }

            return $subject;
        });
    }

    public function publishSubject(Uuid $subjectId): CatalogSubject
    {
        return $this->em->wrapInTransaction(function () use ($subjectId): CatalogSubject {
            $subject = $this->lockSubject($subjectId);
            $subject->publish($this->clock->now());
            $this->em->flush();

            return $subject;
        });
    }

    public function archiveSubject(Uuid $subjectId): CatalogSubject
    {
        return $this->em->wrapInTransaction(function () use ($subjectId): CatalogSubject {
            $subject = $this->lockSubject($subjectId);
            $subject->archive($this->clock->now());
            $this->em->flush();

            return $subject;
        });
    }

    public function createUnit(
        Uuid $subjectId,
        string $name,
        ?string $description,
        int $position,
        ?string $slugOverride = null,
    ): CatalogUnit {
        $name = trim($name);
        $slug = $slugOverride ? $this->slugger->slugify($slugOverride) : $this->slugger->slugify($name);

        return $this->em->wrapInTransaction(function () use ($subjectId, $name, $description, $position, $slug): CatalogUnit {
            $subject = $this->lockSubject($subjectId);
            if ($this->units->existsSlugForSubject($subject, $slug)) {
                throw CatalogException::conflict('Bu ders altında aynı kısa adres zaten kullanılıyor.');
            }
            $unit = CatalogUnit::createDraft($subject, $name, $slug, $description, $position, $this->clock->now());
            try {
                $this->units->save($unit);
            } catch (UniqueConstraintViolationException) {
                throw CatalogException::conflict('Bu ders altında aynı kısa adres zaten kullanılıyor.');
            }

            return $unit;
        });
    }

    public function updateUnit(
        Uuid $unitId,
        string $name,
        ?string $description,
        int $position,
        ?string $slugOverride = null,
    ): CatalogUnit {
        $name = trim($name);

        return $this->em->wrapInTransaction(function () use ($unitId, $name, $description, $position, $slugOverride): CatalogUnit {
            $unit = $this->lockUnit($unitId);
            $slug = $slugOverride ? $this->slugger->slugify($slugOverride) : $this->slugger->slugify($name);
            if ($this->units->existsSlugForSubject($unit->getSubject(), $slug, $unit->getId())) {
                throw CatalogException::conflict('Bu ders altında aynı kısa adres zaten kullanılıyor.');
            }
            $unit->updateDetails($name, $slug, $description, $position, $this->clock->now());
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                throw CatalogException::conflict('Bu ders altında aynı kısa adres zaten kullanılıyor.');
            }

            return $unit;
        });
    }

    public function publishUnit(Uuid $unitId): CatalogUnit
    {
        return $this->em->wrapInTransaction(function () use ($unitId): CatalogUnit {
            $unit = $this->lockUnit($unitId);
            $unit->publish($this->clock->now());
            $this->em->flush();

            return $unit;
        });
    }

    public function archiveUnit(Uuid $unitId): CatalogUnit
    {
        return $this->em->wrapInTransaction(function () use ($unitId): CatalogUnit {
            $unit = $this->lockUnit($unitId);
            $unit->archive($this->clock->now());
            $this->em->flush();

            return $unit;
        });
    }

    public function createTopic(
        Uuid $unitId,
        string $name,
        ?string $summary,
        int $position,
        ?int $estimatedMinutes,
        ?string $slugOverride = null,
    ): CatalogTopic {
        $name = trim($name);
        $slug = $slugOverride ? $this->slugger->slugify($slugOverride, CatalogTopic::SLUG_MAX) : $this->slugger->slugify($name, CatalogTopic::SLUG_MAX);

        return $this->em->wrapInTransaction(function () use ($unitId, $name, $summary, $position, $estimatedMinutes, $slug): CatalogTopic {
            $unit = $this->lockUnit($unitId);
            if ($this->topics->existsSlugForUnit($unit, $slug)) {
                throw CatalogException::conflict('Bu ünite altında aynı kısa adres zaten kullanılıyor.');
            }
            $topic = CatalogTopic::createDraft($unit, $name, $slug, $summary, $position, $estimatedMinutes, $this->clock->now());
            try {
                $this->topics->save($topic);
            } catch (UniqueConstraintViolationException) {
                throw CatalogException::conflict('Bu ünite altında aynı kısa adres zaten kullanılıyor.');
            }

            return $topic;
        });
    }

    public function updateTopic(
        Uuid $topicId,
        string $name,
        ?string $summary,
        int $position,
        ?int $estimatedMinutes,
        ?string $slugOverride = null,
    ): CatalogTopic {
        $name = trim($name);

        return $this->em->wrapInTransaction(function () use ($topicId, $name, $summary, $position, $estimatedMinutes, $slugOverride): CatalogTopic {
            $topic = $this->lockTopic($topicId);
            $slug = $slugOverride ? $this->slugger->slugify($slugOverride, CatalogTopic::SLUG_MAX) : $this->slugger->slugify($name, CatalogTopic::SLUG_MAX);
            if ($this->topics->existsSlugForUnit($topic->getUnit(), $slug, $topic->getId())) {
                throw CatalogException::conflict('Bu ünite altında aynı kısa adres zaten kullanılıyor.');
            }
            $topic->updateDetails($name, $slug, $summary, $position, $estimatedMinutes, $this->clock->now());
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                throw CatalogException::conflict('Bu ünite altında aynı kısa adres zaten kullanılıyor.');
            }

            return $topic;
        });
    }

    public function publishTopic(Uuid $topicId): CatalogTopic
    {
        return $this->em->wrapInTransaction(function () use ($topicId): CatalogTopic {
            $topic = $this->lockTopic($topicId);
            $topic->publish($this->clock->now());
            $this->em->flush();

            return $topic;
        });
    }

    public function archiveTopic(Uuid $topicId): CatalogTopic
    {
        return $this->em->wrapInTransaction(function () use ($topicId): CatalogTopic {
            $topic = $this->lockTopic($topicId);
            $topic->archive($this->clock->now());
            $this->em->flush();

            return $topic;
        });
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

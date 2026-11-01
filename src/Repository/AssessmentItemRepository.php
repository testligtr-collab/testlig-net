<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssessmentItem;
use App\Entity\Question;
use App\Enum\AssessmentScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AssessmentItem>
 */
class AssessmentItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssessmentItem::class);
    }

    public function save(AssessmentItem $item, bool $flush = true): void
    {
        $this->getEntityManager()->persist($item);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Platform test rows for the given questions. Institution assessments are not loaded.
     *
     * @param list<Question> $questions
     *
     * @return list<AssessmentItem>
     */
    public function findPlatformUsages(array $questions): array
    {
        if ([] === $questions) {
            return [];
        }

        $qb = $this->createQueryBuilder('item')
            ->addSelect('assessmentRevision', 'assessment', 'questionRevision', 'question')
            ->innerJoin('item.assessmentRevision', 'assessmentRevision')
            ->innerJoin('assessmentRevision.assessment', 'assessment')
            ->innerJoin('item.questionRevision', 'questionRevision')
            ->innerJoin('item.question', 'question')
            ->andWhere('assessment.scope = :scope')
            ->andWhere('assessment.institution IS NULL')
            ->setParameter('scope', AssessmentScope::Platform)
            ->orderBy('assessment.code', 'ASC')
            ->addOrderBy('assessmentRevision.revisionNumber', 'ASC')
            ->addOrderBy('item.position', 'ASC');
        $match = $qb->expr()->orX();
        foreach ($questions as $index => $question) {
            $match->add('IDENTITY(item.question) = :question'.$index);
            $qb->setParameter('question'.$index, $question->getId(), 'uuid');
        }

        /** @var list<AssessmentItem> $rows */
        $rows = $qb->andWhere($match)->getQuery()->getResult();

        return $rows;
    }

    /**
     * Ordered preview rows for one assessment revision. One query joins the section,
     * the pinned question revision, and the question. It does not select explanation,
     * answer keys, or option stable keys.
     *
     * @return list<array{position: int, points: string, code: string, questionRevisionId: string, stemContent: array<string, mixed>|null}>
     */
    public function findPreviewRows(Uuid $assessmentRevisionId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('item')
            ->select('item.position AS itemPosition')
            ->addSelect('item.points AS points')
            ->addSelect('question.code AS code')
            ->addSelect('questionRevision.id AS questionRevisionId')
            ->addSelect('questionRevision.stemContent AS stemContent')
            ->innerJoin('item.section', 'section')
            ->innerJoin('item.question', 'question')
            ->innerJoin('item.questionRevision', 'questionRevision')
            ->andWhere('item.assessmentRevision = :revision')
            ->setParameter('revision', $assessmentRevisionId, 'uuid')
            ->orderBy('section.position', 'ASC')
            ->addOrderBy('item.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $preview = [];
        foreach ($rows as $row) {
            $revisionId = self::uuidKey($row['questionRevisionId'] ?? null);
            $position = $row['itemPosition'] ?? null;
            if (\is_string($position) && 1 === preg_match('/^\d+$/', $position)) {
                $position = (int) $position;
            }
            $points = $row['points'] ?? null;
            if (\is_int($points) || \is_float($points)) {
                $points = number_format((float) $points, 2, '.', '');
            }
            $code = $row['code'] ?? null;
            if (null === $revisionId || !\is_int($position) || !\is_string($points) || !\is_string($code)) {
                continue;
            }
            $preview[] = [
                'position' => $position,
                'points' => $points,
                'code' => $code,
                'questionRevisionId' => $revisionId,
                'stemContent' => self::document($row['stemContent'] ?? null),
            ];
        }

        return $preview;
    }

    private static function uuidKey(mixed $value): ?string
    {
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        if (Uuid::isValid($value)) {
            return Uuid::fromString($value)->toRfc4122();
        }
        if (1 === preg_match('/^[0-9a-f]{32}$/i', $value)) {
            $hex = strtolower($value);
            $canonical = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);

            return Uuid::isValid($canonical) ? $canonical : null;
        }
        if (16 === \strlen($value)) {
            try {
                return Uuid::fromBinary($value)->toRfc4122();
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function document(mixed $value): ?array
    {
        if (\is_string($value)) {
            $decoded = json_decode($value, true);
            $value = \is_array($decoded) ? $decoded : null;
        }
        if (!\is_array($value)) {
            return null;
        }
        $document = [];
        foreach ($value as $key => $item) {
            if (\is_string($key)) {
                $document[$key] = $item;
            }
        }

        return $document;
    }
}

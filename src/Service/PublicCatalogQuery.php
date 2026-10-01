<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CatalogSourceAttribution;
use App\Dto\PublicCatalogGradeCard;
use App\Dto\PublicCatalogSubjectCard;
use App\Dto\PublicCatalogSubjectPage;
use App\Dto\PublicCatalogTopicCard;
use App\Dto\PublicCatalogUnitCard;
use App\Dto\PublicCatalogUnitPage;
use App\Dto\PublicSitemapUrl;
use App\Entity\CatalogSubject;
use App\Entity\CatalogTopic;
use App\Entity\CatalogUnit;
use App\Enum\CatalogPublicationStatus;
use App\Enum\GradeLevel;
use App\Util\HttpsUrl;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Published catalog read model for the public pages. Scalar queries only.
 * Learning content, questions, and user rows are never loaded.
 */
final class PublicCatalogQuery
{
    private const TONES = ['pink', 'yellow', 'mint', 'blue', 'purple', 'peach'];

    private int $statements = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function statements(): int
    {
        return $this->statements;
    }

    /**
     * @return list<PublicCatalogGradeCard>
     */
    public function grades(): array
    {
        $this->statements = 0;
        $rows = $this->rows(
            'SELECT s.gradeLevel AS grade, COUNT(s.id) AS subjectCount
             FROM '.CatalogSubject::class.' s
             WHERE s.status = :published
             GROUP BY s.gradeLevel
             ORDER BY s.gradeLevel ASC',
        );
        $cards = [];
        foreach ($rows as $row) {
            $grade = $this->gradeLevel($row['grade'] ?? null);
            if (!$grade instanceof GradeLevel) {
                continue;
            }
            $cards[] = new PublicCatalogGradeCard($grade->value, $this->int($row['subjectCount'] ?? 0), self::TONES[($grade->value - 1) % \count(self::TONES)]);
        }

        return $cards;
    }

    /**
     * @return list<PublicCatalogSubjectCard>|null
     */
    public function grade(GradeLevel $grade): ?array
    {
        $this->statements = 0;
        $subjects = $this->subjectRows($grade);
        if ([] === $subjects) {
            return null;
        }
        $unitCounts = $this->countsBySlug(
            'SELECT s.slug AS slug, COUNT(u.id) AS total
             FROM '.CatalogUnit::class.' u
             JOIN u.subject s
             WHERE s.gradeLevel = :grade AND s.status = :published AND u.status = :published
             GROUP BY s.slug',
            $grade,
        );
        $topicCounts = $this->countsBySlug(
            'SELECT s.slug AS slug, COUNT(t.id) AS total
             FROM '.CatalogTopic::class.' t
             JOIN t.unit u
             JOIN u.subject s
             WHERE s.gradeLevel = :grade AND s.status = :published AND u.status = :published AND t.status = :published
             GROUP BY s.slug',
            $grade,
        );
        $cards = [];
        foreach ($subjects as $row) {
            $slug = $this->string($row['slug'] ?? null);
            $cards[] = new PublicCatalogSubjectCard(
                $grade->value,
                $this->string($row['name'] ?? null),
                $slug,
                $this->optionalText($row['description'] ?? null),
                $unitCounts[$slug] ?? 0,
                $topicCounts[$slug] ?? 0,
            );
        }

        return $cards;
    }

    public function subject(GradeLevel $grade, string $subjectSlug): ?PublicCatalogSubjectPage
    {
        $this->statements = 0;
        $subject = $this->one(
            'SELECT s.name AS name, s.slug AS slug, s.description AS description
             FROM '.CatalogSubject::class.' s
             WHERE s.gradeLevel = :grade AND s.slug = :subjectSlug AND s.status = :published',
            ['grade' => $grade, 'subjectSlug' => $subjectSlug],
        );
        if (null === $subject) {
            return null;
        }
        $units = $this->rows(
            'SELECT u.name AS name, u.slug AS slug, u.description AS description, u.sourceUrl AS sourceUrl
             FROM '.CatalogUnit::class.' u
             JOIN u.subject s
             WHERE s.gradeLevel = :grade AND s.slug = :subjectSlug AND s.status = :published AND u.status = :published
             ORDER BY u.position ASC, u.name ASC, u.slug ASC',
            ['grade' => $grade, 'subjectSlug' => $subjectSlug],
        );
        $topicCounts = $this->countsBySlug(
            'SELECT u.slug AS slug, COUNT(t.id) AS total
             FROM '.CatalogTopic::class.' t
             JOIN t.unit u
             JOIN u.subject s
             WHERE s.gradeLevel = :grade AND s.slug = :subjectSlug
               AND s.status = :published AND u.status = :published AND t.status = :published
             GROUP BY u.slug',
            $grade,
            $subjectSlug,
        );
        $slug = $this->string($subject['slug'] ?? null);
        $cards = [];
        $topicTotal = 0;
        foreach ($units as $row) {
            $unitSlug = $this->string($row['slug'] ?? null);
            $count = $topicCounts[$unitSlug] ?? 0;
            $topicTotal += $count;
            $cards[] = new PublicCatalogUnitCard(
                $this->string($row['name'] ?? null),
                $unitSlug,
                $this->optionalText($row['description'] ?? null),
                $count,
                $this->sourceUrl($row['sourceUrl'] ?? null),
            );
        }

        return new PublicCatalogSubjectPage(
            new PublicCatalogSubjectCard(
                $grade->value,
                $this->string($subject['name'] ?? null),
                $slug,
                $this->optionalText($subject['description'] ?? null),
                \count($cards),
                $topicTotal,
            ),
            $cards,
        );
    }

    public function unit(GradeLevel $grade, string $subjectSlug, string $unitSlug): ?PublicCatalogUnitPage
    {
        $this->statements = 0;
        $unit = $this->one(
            'SELECT s.name AS subjectName, s.slug AS subjectSlug, s.description AS subjectDescription,
                    u.name AS name, u.slug AS slug, u.description AS description, u.sourceUrl AS sourceUrl
             FROM '.CatalogUnit::class.' u
             JOIN u.subject s
             WHERE s.gradeLevel = :grade AND s.slug = :subjectSlug AND u.slug = :unitSlug
               AND s.status = :published AND u.status = :published',
            ['grade' => $grade, 'subjectSlug' => $subjectSlug, 'unitSlug' => $unitSlug],
        );
        if (null === $unit) {
            return null;
        }
        $topics = $this->rows(
            'SELECT t.name AS name, t.slug AS slug, t.summary AS summary
             FROM '.CatalogTopic::class.' t
             JOIN t.unit u
             JOIN u.subject s
             WHERE s.gradeLevel = :grade AND s.slug = :subjectSlug AND u.slug = :unitSlug
               AND s.status = :published AND u.status = :published AND t.status = :published
             ORDER BY t.position ASC, t.name ASC, t.slug ASC',
            ['grade' => $grade, 'subjectSlug' => $subjectSlug, 'unitSlug' => $unitSlug],
        );
        $topicCards = [];
        foreach ($topics as $row) {
            $topicCards[] = new PublicCatalogTopicCard(
                $this->string($row['name'] ?? null),
                $this->string($row['slug'] ?? null),
                $this->optionalText($row['summary'] ?? null),
            );
        }

        return new PublicCatalogUnitPage(
            new PublicCatalogSubjectCard(
                $grade->value,
                $this->string($unit['subjectName'] ?? null),
                $this->string($unit['subjectSlug'] ?? null),
                $this->optionalText($unit['subjectDescription'] ?? null),
                1,
                \count($topicCards),
            ),
            new PublicCatalogUnitCard(
                $this->string($unit['name'] ?? null),
                $this->string($unit['slug'] ?? null),
                $this->optionalText($unit['description'] ?? null),
                \count($topicCards),
                $this->sourceUrl($unit['sourceUrl'] ?? null),
            ),
            $topicCards,
        );
    }

    /**
     * @return list<PublicSitemapUrl>
     */
    public function sitemapEntries(): array
    {
        $this->statements = 0;
        $subjects = $this->rows(
            'SELECT s.gradeLevel AS grade, s.slug AS slug, s.publishedAt AS publishedAt
             FROM '.CatalogSubject::class.' s
             WHERE s.status = :published
             ORDER BY s.gradeLevel ASC, s.position ASC, s.slug ASC',
        );
        $units = $this->rows(
            'SELECT s.gradeLevel AS grade, s.slug AS subjectSlug, u.slug AS slug, u.publishedAt AS publishedAt
             FROM '.CatalogUnit::class.' u
             JOIN u.subject s
             WHERE s.status = :published AND u.status = :published
             ORDER BY s.gradeLevel ASC, s.position ASC, s.slug ASC, u.position ASC, u.slug ASC',
        );
        $entries = [];
        $grades = [];
        foreach ($subjects as $row) {
            $grade = $this->gradeLevel($row['grade'] ?? null);
            $slug = $this->string($row['slug'] ?? null);
            if (!$grade instanceof GradeLevel || '' === $slug) {
                continue;
            }
            $grades[$grade->value] = true;
            $entries[] = new PublicSitemapUrl('/dersler/'.$grade->value.'/'.$slug, $this->instant($row['publishedAt'] ?? null));
        }
        $gradeEntries = [];
        foreach (array_keys($grades) as $grade) {
            $gradeEntries[] = new PublicSitemapUrl('/dersler/'.$grade, null);
        }
        $unitEntries = [];
        foreach ($units as $row) {
            $grade = $this->gradeLevel($row['grade'] ?? null);
            $subjectSlug = $this->string($row['subjectSlug'] ?? null);
            $slug = $this->string($row['slug'] ?? null);
            if (!$grade instanceof GradeLevel || '' === $subjectSlug || '' === $slug) {
                continue;
            }
            $unitEntries[] = new PublicSitemapUrl(
                '/dersler/'.$grade->value.'/'.$subjectSlug.'/'.$slug,
                $this->instant($row['publishedAt'] ?? null),
            );
        }

        return [...$gradeEntries, ...$entries, ...$unitEntries];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $dql, array $params = []): array
    {
        ++$this->statements;
        $query = $this->entityManager->createQuery($dql);
        $query->setParameter('published', CatalogPublicationStatus::Published);
        foreach ($params as $name => $value) {
            $query->setParameter($name, $value);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $query->getArrayResult();

        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function one(string $dql, array $params): ?array
    {
        $rows = $this->rows($dql, $params);

        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subjectRows(GradeLevel $grade): array
    {
        return $this->rows(
            'SELECT s.name AS name, s.slug AS slug, s.description AS description
             FROM '.CatalogSubject::class.' s
             WHERE s.gradeLevel = :grade AND s.status = :published
             ORDER BY s.position ASC, s.name ASC, s.slug ASC',
            ['grade' => $grade],
        );
    }

    /**
     * @return array<string, int>
     */
    private function countsBySlug(string $dql, GradeLevel $grade, ?string $subjectSlug = null): array
    {
        $params = ['grade' => $grade];
        if (null !== $subjectSlug) {
            $params['subjectSlug'] = $subjectSlug;
        }
        $counts = [];
        foreach ($this->rows($dql, $params) as $row) {
            $counts[$this->string($row['slug'] ?? null)] = $this->int($row['total'] ?? 0);
        }

        return $counts;
    }

    private function gradeLevel(mixed $value): ?GradeLevel
    {
        if ($value instanceof GradeLevel) {
            return $value;
        }
        if (\is_int($value) || (\is_string($value) && 1 === preg_match('/^\d+$/', $value))) {
            return GradeLevel::tryFrom((int) $value);
        }

        return null;
    }

    private function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function optionalText(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $text = trim($value);

        return '' === $text ? null : $text;
    }

    private function sourceUrl(mixed $value): ?string
    {
        if (!\is_string($value) || !HttpsUrl::isValid($value, CatalogSourceAttribution::URL_MAX)) {
            return null;
        }

        return $value;
    }

    private function instant(mixed $value): ?\DateTimeImmutable
    {
        return $value instanceof \DateTimeImmutable ? $value : null;
    }

    private function int(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}

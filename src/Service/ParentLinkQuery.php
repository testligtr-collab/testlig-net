<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ParentChildView;
use App\Dto\ParentTestSummaryPage;
use App\Dto\ParentTestSummaryView;
use App\Dto\StudentOpenLinkCodeView;
use App\Dto\StudentParentLinkView;
use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentPlatformPractice;
use App\Entity\AssessmentResultActiveReleaseGuard;
use App\Entity\AssessmentScoringRun;
use App\Entity\ParentStudentLink;
use App\Entity\User;
use App\Enum\AssessmentAttemptStatus;
use App\Repository\CatalogSubjectRepository;
use App\Repository\ParentStudentLinkCodeRepository;
use App\Repository\ParentStudentLinkRepository;
use App\Repository\StudentProfileRepository;
use App\Time\UtcInstant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Verified-link reads for the student profile and the parent panel.
 * Rows are re-checked here; templates never receive entities.
 */
final class ParentLinkQuery
{
    public const PAGE_SIZE = 8;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ParentStudentLinkRepository $links,
        private readonly ParentStudentLinkCodeRepository $codes,
        private readonly StudentProfileRepository $profiles,
        private readonly CatalogSubjectRepository $subjects,
        private readonly InvitationCodeDigestHasher $hasher,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<StudentParentLinkView>
     */
    public function parentsForStudent(User $student): array
    {
        $views = [];
        foreach ($this->links->findVerifiedForStudent($student->getId()) as $link) {
            if (!$link->getStudent()->getId()->equals($student->getId()) || !$link->isVerified()) {
                continue;
            }
            $parent = $link->getParent();
            $views[] = new StudentParentLinkView(
                $this->hasher->parentPanelReference($link->getId()),
                trim($parent->getFirstName().' '.$parent->getLastName()),
                $this->stamp($link->getVerifiedAt()),
            );
        }

        return $views;
    }

    public function openCodeForStudent(User $student): ?StudentOpenLinkCodeView
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $code = $this->codes->findLiveForStudent($student->getId(), $now);
        if (null === $code) {
            return null;
        }

        return new StudentOpenLinkCodeView($this->stamp($code->getExpiresAt()));
    }

    public function linkIdForStudentReference(User $student, string $reference): ?Uuid
    {
        foreach ($this->links->findVerifiedForStudent($student->getId()) as $link) {
            if ($this->referenceMatches($link, $reference) && $link->getStudent()->getId()->equals($student->getId())) {
                return $link->getId();
            }
        }

        return null;
    }

    /**
     * @return list<ParentChildView>
     */
    public function childrenForParent(User $parent): array
    {
        $links = $this->verifiedLinksForParent($parent);
        $recent = $this->recentByStudent($links);

        $views = [];
        foreach ($links as $link) {
            $views[] = $this->childView($link, $recent[$link->getStudent()->getId()->toRfc4122()] ?? []);
        }

        return $views;
    }

    public function childForParent(User $parent, string $reference): ?ParentChildView
    {
        $link = $this->linkForParentReference($parent, $reference);
        if (!$link instanceof ParentStudentLink) {
            return null;
        }
        $recent = $this->recentByStudent([$link]);

        return $this->childView($link, $recent[$link->getStudent()->getId()->toRfc4122()] ?? []);
    }

    public function testsForParent(User $parent, string $reference, int $page): ?ParentTestSummaryPage
    {
        $link = $this->linkForParentReference($parent, $reference);
        if (!$link instanceof ParentStudentLink) {
            return null;
        }
        $page = max(1, $page);
        $student = $link->getStudent();
        $total = $this->countAttempts($student);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pages) {
            $page = $pages;
        }
        $attempts = $this->attemptPage($student, $page);
        $releases = $this->releasedRuns($attempts);

        return new ParentTestSummaryPage(
            $this->mapAttempts($attempts, $releases),
            $page,
            $pages,
            $this->hasher->parentPanelReference($link->getId()),
            trim($student->getFirstName().' '.$student->getLastName()),
        );
    }

    /**
     * @return list<ParentStudentLink>
     */
    private function verifiedLinksForParent(User $parent): array
    {
        return array_values(array_filter(
            $this->links->findVerifiedForParent($parent->getId()),
            static fn (ParentStudentLink $link): bool => $link->isVerified() && $link->getParent()->getId()->equals($parent->getId()),
        ));
    }

    private function linkForParentReference(User $parent, string $reference): ?ParentStudentLink
    {
        foreach ($this->verifiedLinksForParent($parent) as $link) {
            if ($this->referenceMatches($link, $reference)) {
                return $link;
            }
        }

        return null;
    }

    private function referenceMatches(ParentStudentLink $link, string $reference): bool
    {
        $expected = $this->hasher->parentPanelReference($link->getId());
        if (\strlen($expected) !== \strlen($reference)) {
            return false;
        }

        return hash_equals($expected, $reference);
    }

    /**
     * @param list<ParentTestSummaryView> $recent
     */
    private function childView(ParentStudentLink $link, array $recent): ParentChildView
    {
        $student = $link->getStudent();
        $profile = $this->profiles->findOneByUserId($student->getId());
        $grade = $profile?->getGradeLevel();
        $names = [];
        if (null !== $grade) {
            foreach ($this->subjects->findPublishedByGrade($grade) as $subject) {
                $name = trim($subject->getName());
                if ('' !== $name) {
                    $names[] = $name;
                }
            }
        }

        return new ParentChildView(
            $this->hasher->parentPanelReference($link->getId()),
            trim($student->getFirstName().' '.$student->getLastName()),
            null === $grade ? 'Sınıf bilgisi yok' : $grade->value.'. sınıf',
            $names,
            $recent,
        );
    }

    /**
     * @param list<ParentStudentLink> $links
     *
     * @return array<string, list<ParentTestSummaryView>>
     */
    private function recentByStudent(array $links): array
    {
        if ([] === $links) {
            return [];
        }
        $hexes = [];
        foreach ($links as $link) {
            $hexes[] = strtoupper(bin2hex($link->getStudent()->getId()->toBinary()));
        }
        $placeholders = [];
        $params = [];
        foreach ($hexes as $index => $hex) {
            $name = 'userHex'.$index;
            $placeholders[] = ':'.$name;
            $params[$name] = $hex;
        }
        $sql = 'SELECT HEX(ranked.id) AS attempt_hex, HEX(ranked.user_id) AS user_hex FROM (
            SELECT a.id AS id, a.user_id AS user_id,
                   ROW_NUMBER() OVER (
                       PARTITION BY a.user_id
                       ORDER BY COALESCE(a.submitted_at, a.started_at) DESC, a.id DESC
                   ) AS rn
            FROM assessment_attempts a
            INNER JOIN assessment_platform_practices p
                ON p.delivery_id = a.delivery_id AND p.user_id = a.user_id
            WHERE HEX(a.user_id) IN ('.implode(', ', $placeholders).')
        ) ranked WHERE ranked.rn <= 3';
        $rows = $this->entityManager->getConnection()->fetchAllAssociative($sql, $params);
        $ids = [];
        foreach ($rows as $row) {
            $hex = $row['attempt_hex'] ?? null;
            if (\is_string($hex) && 32 === \strlen($hex)) {
                $binary = hex2bin($hex);
                if (\is_string($binary)) {
                    $ids[] = Uuid::fromBinary($binary);
                }
            }
        }
        if ([] === $ids) {
            return [];
        }
        $attempts = $this->hydrateAttempts($ids);
        $releases = $this->releasedRuns($attempts);
        $mapped = $this->mapAttempts($attempts, $releases);
        $byUser = [];
        foreach ($attempts as $index => $attempt) {
            $view = $mapped[$index] ?? null;
            if (!$view instanceof ParentTestSummaryView) {
                continue;
            }
            $byUser[$attempt->getUser()->getId()->toRfc4122()][] = $view;
        }

        return $byUser;
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<AssessmentAttempt>
     */
    private function hydrateAttempts(array $ids): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('a', 'rev', 'ass', 'subj')
            ->from(AssessmentAttempt::class, 'a')
            ->innerJoin('a.assessmentRevision', 'rev')
            ->innerJoin('a.assessment', 'ass')
            ->leftJoin('ass.subject', 'subj');
        $or = [];
        foreach ($ids as $index => $id) {
            $name = 'attemptId'.$index;
            $or[] = 'a.id = :'.$name;
            $query->setParameter($name, $id, 'uuid');
        }
        $query->andWhere(implode(' OR ', $or));

        /** @var list<AssessmentAttempt> $attempts */
        $attempts = $query->getQuery()->getResult();

        return $attempts;
    }

    private function countAttempts(User $student): int
    {
        return (int) $this->attemptBase($student)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<AssessmentAttempt>
     */
    private function attemptPage(User $student, int $page): array
    {
        /** @var list<AssessmentAttempt> $rows */
        $rows = $this->attemptBase($student)
            ->select('a', 'rev', 'ass', 'subj')
            ->orderBy('a.submittedAt', 'DESC')
            ->addOrderBy('a.startedAt', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    private function attemptBase(User $student): \Doctrine\ORM\QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(AssessmentAttempt::class, 'a')
            ->innerJoin('a.assessmentRevision', 'rev')
            ->innerJoin('a.assessment', 'ass')
            ->leftJoin('ass.subject', 'subj')
            ->innerJoin(
                AssessmentPlatformPractice::class,
                'practice',
                'WITH',
                'practice.delivery = a.delivery AND practice.user = a.user',
            )
            ->andWhere('a.user = :student')
            ->setParameter('student', $student->getId(), 'uuid');
    }

    /**
     * @param list<AssessmentAttempt> $attempts
     *
     * @return array<string, AssessmentScoringRun>
     */
    private function releasedRuns(array $attempts): array
    {
        if ([] === $attempts) {
            return [];
        }
        $qb = $this->entityManager->createQueryBuilder()
            ->select('g', 'rel', 'run', 'attempt')
            ->from(AssessmentResultActiveReleaseGuard::class, 'g')
            ->innerJoin('g.release', 'rel')
            ->innerJoin('rel.scoringRun', 'run')
            ->innerJoin('g.attempt', 'attempt');
        $clauses = [];
        foreach ($attempts as $index => $attempt) {
            $name = 'releasedAttempt'.$index;
            $clauses[] = 'attempt.id = :'.$name;
            $qb->setParameter($name, $attempt->getId(), 'uuid');
        }
        $guards = $qb->andWhere(implode(' OR ', $clauses))->getQuery()->getResult();
        $runs = [];
        foreach ($guards as $guard) {
            if (!$guard instanceof AssessmentResultActiveReleaseGuard) {
                continue;
            }
            $runs[$guard->getAttemptId()->toRfc4122()] = $guard->getRelease()->getScoringRun();
        }

        return $runs;
    }

    /**
     * @param list<AssessmentAttempt>             $attempts
     * @param array<string, AssessmentScoringRun> $releases
     *
     * @return list<ParentTestSummaryView>
     */
    private function mapAttempts(array $attempts, array $releases): array
    {
        $views = [];
        foreach ($attempts as $attempt) {
            $inProgress = AssessmentAttemptStatus::InProgress === $attempt->getStatus();
            $run = $releases[$attempt->getId()->toRfc4122()] ?? null;
            $published = !$inProgress && $run instanceof AssessmentScoringRun;
            $title = trim($attempt->getAssessmentRevision()->getTitle());
            $subject = trim($attempt->getAssessment()->getSubject()?->getName() ?? '');
            $views[] = new ParentTestSummaryView(
                '' !== $title ? $title : 'Test',
                $subject,
                $inProgress ? 'Devam ediyor' : ($published ? 'Tamamlandı' : 'Sonuç henüz yayımlanmadı'),
                $this->stamp($attempt->getSubmittedAt() ?? $attempt->getStartedAt()),
                $published,
                $published ? $this->points($run->getFinalPoints()) : null,
                $published ? $this->points($run->getMaximumPoints()) : null,
                $published ? $this->percent($run->getPercentage()) : null,
                $published ? $run->getCorrectCount() : null,
                $published ? $run->getIncorrectCount() : null,
                $published ? $run->getUnansweredCount() : null,
            );
        }

        return $views;
    }

    private function points(string $value): string
    {
        return str_replace('.', ',', $this->scale($value, 2));
    }

    private function percent(string $value): string
    {
        return str_replace('.', ',', $this->scale($value, 1)).'%';
    }

    /**
     * @return numeric-string
     */
    private function scale(string $value, int $scale): string
    {
        $trimmed = trim($value);
        if ('' === $trimmed || !is_numeric($trimmed) || 1 !== preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            return bcadd('0', '0', $scale);
        }

        return bcadd($trimmed, '0', $scale);
    }

    private function stamp(?\DateTimeImmutable $at): string
    {
        if (null === $at) {
            return '';
        }

        return UtcInstant::ensure($at)->format('d.m.Y H:i').' UTC';
    }
}

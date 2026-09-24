<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\AdminLearningContentListItemView;
use App\Dto\AdminPagedResult;
use App\Entity\LearningContent;
use App\Entity\LearningContentAccessPolicy;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\LearningContentStatus;
use App\Enum\UserRole;
use App\Exception\CommerceException;
use App\Repository\LearningContentAccessPolicyRepository;
use App\Repository\LearningContentRepository;
use App\Security\AdminAuthorization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Paginated LearningContent list for the admin content workspace.
 *
 * @phpstan-type ContentFilters array{
 *     q?: ?string,
 *     status?: ?string,
 *     subject_id?: ?string,
 *     grade?: ?string,
 *     page?: int,
 *     page_size?: int
 * }
 */
final class AdminLearningContentQuery
{
    private const ALLOWED_FILTER_KEYS = [
        'q',
        'status',
        'subject_id',
        'grade',
        'page',
        'page_size',
    ];

    public function __construct(
        private readonly AdminActorGuard $actorGuard,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly LearningContentRepository $contents,
        private readonly LearningContentAccessPolicyRepository $policies,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $rawFilters
     *
     * @return AdminPagedResult<AdminLearningContentListItemView>
     */
    public function listContents(Uuid $actorId, array $rawFilters = []): AdminPagedResult
    {
        $actor = $this->actorGuard->withFreshActor(
            $actorId,
            $this->adminAuthorization->assertCanViewLearningContentWorkspace(...),
        );
        $filters = $this->normalizeFilters($rawFilters);
        $page = AdminPagination::normalizePage((int) ($filters['page'] ?? 1));
        $pageSize = AdminPagination::normalizePageSize((int) ($filters['page_size'] ?? AdminPagination::DEFAULT_PAGE_SIZE));

        $qb = $this->em->createQueryBuilder()
            ->select('c', 's', 'author')
            ->from(LearningContent::class, 'c')
            ->innerJoin('c.subject', 's')
            ->innerJoin('c.createdBy', 'author');

        if ($this->isTeacherOnly($actor)) {
            $qb->andWhere('IDENTITY(c.createdBy) = :actorId')
                ->setParameter('actorId', $actor->getId(), 'uuid');
        }

        $q = $filters['q'] ?? null;
        if (\is_string($q) && '' !== $q) {
            try {
                $term = AdminLikeEscape::normalizeSearch($q);
            } catch (\InvalidArgumentException) {
                throw CommerceException::invalidInput('Arama metni çok uzun.');
            }
            if (null !== $term) {
                $pattern = AdminLikeEscape::containsPattern($term);
                $qb->andWhere(
                    "(c.title LIKE :q ESCAPE '!' OR c.code LIKE :q ESCAPE '!' OR c.normalizedTitle LIKE :q ESCAPE '!')",
                )->setParameter('q', $pattern);
            }
        }

        $statusRaw = $filters['status'] ?? null;
        if (\is_string($statusRaw) && '' !== $statusRaw) {
            $status = LearningContentStatus::tryFrom($statusRaw);
            if (!$status instanceof LearningContentStatus) {
                throw CommerceException::invalidInput('Geçersiz durum filtresi.');
            }
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }

        $subjectRaw = $filters['subject_id'] ?? null;
        if (\is_string($subjectRaw) && '' !== $subjectRaw) {
            try {
                $subjectId = Uuid::fromString($subjectRaw);
            } catch (\InvalidArgumentException) {
                throw CommerceException::invalidInput('Geçersiz konu alanı filtresi.');
            }
            $qb->andWhere('IDENTITY(c.subject) = :subjectId')
                ->setParameter('subjectId', $subjectId, 'uuid');
        }

        $gradeRaw = $filters['grade'] ?? null;
        if (\is_string($gradeRaw) && '' !== $gradeRaw) {
            $grade = GradeLevel::tryFrom((int) $gradeRaw);
            if (!$grade instanceof GradeLevel) {
                throw CommerceException::invalidInput('Geçersiz sınıf filtresi.');
            }
            $qb->andWhere('c.gradeLevel = :grade')->setParameter('grade', $grade);
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<LearningContent> $rows */
        $rows = $qb
            ->orderBy('c.updatedAt', 'DESC')
            ->addOrderBy('c.id', 'ASC')
            ->setFirstResult(AdminPagination::offset($page, $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $content) {
            $policy = $this->policies->findForContent($content->getId());
            $author = $content->getCreatedBy();
            $items[] = new AdminLearningContentListItemView(
                id: $content->getId(),
                title: $content->getTitle(),
                code: $content->getCode(),
                contentType: $content->getContentType(),
                status: $content->getStatus(),
                gradeLevel: $content->getGradeLevel(),
                subjectName: $content->getSubject()->getName(),
                subjectId: $content->getSubject()->getId(),
                authorName: trim($author->getFirstName().' '.$author->getLastName()),
                createdById: $author->getId(),
                accessClass: $policy instanceof LearningContentAccessPolicy ? $policy->getAccessClass()->value : null,
                updatedAt: $content->getUpdatedAt(),
            );
        }

        return new AdminPagedResult($items, $page, $pageSize, $total);
    }

    public function requireVisibleContent(Uuid $actorId, Uuid $contentId): LearningContent
    {
        $actor = $this->actorGuard->withFreshActor(
            $actorId,
            $this->adminAuthorization->assertCanViewLearningContentWorkspace(...),
        );
        $content = $this->contents->findOneById($contentId);
        if (!$content instanceof LearningContent) {
            throw CommerceException::notFound();
        }
        if ($this->isTeacherOnly($actor) && !$content->getCreatedBy()->getId()->equals($actor->getId())) {
            throw CommerceException::notFound();
        }

        return $content;
    }

    private function isTeacherOnly(User $actor): bool
    {
        $roles = $actor->getRoles();
        $isTeacher = \in_array(UserRole::Teacher->value, $roles, true);
        if (!$isTeacher) {
            return false;
        }

        return !$this->adminAuthorization->canMapCatalogCanonical($actor)
            && !\in_array(UserRole::Moderator->value, $roles, true);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return ContentFilters
     */
    private function normalizeFilters(array $raw): array
    {
        $out = [];
        foreach (self::ALLOWED_FILTER_KEYS as $key) {
            if (\array_key_exists($key, $raw)) {
                $out[$key] = $raw[$key];
            }
        }

        return $out;
    }
}

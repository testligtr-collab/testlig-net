<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningContent;
use App\Entity\Subject;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentStatus;
use App\LearningContent\Document\VideoUrlParser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LearningContent>
 */
class LearningContentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningContent::class);
    }

    public function findOneById(Uuid $id): ?LearningContent
    {
        return $this->find($id);
    }

    /**
     * Published platform contents for a canonical subject (placement bind choices).
     *
     * @return list<LearningContent>
     */
    public function findPublishedPlatformBySubject(Subject $subject): array
    {
        /** @var list<LearningContent> $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('IDENTITY(c.subject) = :subjectId')
            ->andWhere('c.scope = :scope')
            ->andWhere('c.status = :status')
            ->setParameter('subjectId', $subject->getId(), 'uuid')
            ->setParameter('scope', LearningContentScope::Platform)
            ->setParameter('status', LearningContentStatus::Published)
            ->orderBy('c.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Platform contents that share a stable code. More than one row is a conflict.
     *
     * @return list<LearningContent>
     */
    public function findPlatformByCode(string $code): array
    {
        /** @var list<LearningContent> $rows */
        $rows = $this->createQueryBuilder('content')
            ->andWhere('content.code = :code')
            ->andWhere('content.scope = :scope')
            ->setParameter('code', $code)
            ->setParameter('scope', LearningContentScope::Platform)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function save(LearningContent $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function countPlatformLinkedVideosForAdmin(): int
    {
        return \count($this->platformLinkedVideoInventoryRows());
    }

    /**
     * Platform LearningContent current-revision video blocks only (YouTube/Vimeo).
     *
     * @return list<array{content_id: string, content_title: string, provider: string}>
     */
    public function listPlatformLinkedVideosForAdmin(int $offset, int $limit): array
    {
        $rows = $this->platformLinkedVideoInventoryRows();
        if ($offset < 0) {
            $offset = 0;
        }
        if ($limit < 1) {
            return [];
        }

        return \array_slice($rows, $offset, $limit);
    }

    /**
     * One query: platform contents with current revision; video blocks validated in memory.
     * Top-level video only (schema forbids nested video). Callout children are not scanned for video.
     *
     * @return list<array{content_id: string, content_title: string, provider: string}>
     */
    private function platformLinkedVideoInventoryRows(): array
    {
        /** @var list<LearningContent> $contents */
        $contents = $this->createQueryBuilder('content')
            ->select('content', 'revision')
            ->innerJoin('content.currentRevision', 'revision')
            ->andWhere('content.scope = :scope')
            ->setParameter('scope', LearningContentScope::Platform)
            ->orderBy('content.updatedAt', 'DESC')
            ->addOrderBy('content.id', 'DESC')
            ->getQuery()
            ->getResult();

        $rows = [];
        foreach ($contents as $content) {
            $revision = $content->getCurrentRevision();
            if (null === $revision) {
                continue;
            }
            $blocks = $revision->getStructuredContent()['blocks'] ?? [];
            if (!\is_array($blocks)) {
                continue;
            }
            foreach ($blocks as $block) {
                if (!\is_array($block) || 'video' !== ($block['type'] ?? null)) {
                    continue;
                }
                $provider = $block['provider'] ?? null;
                $id = $block['providerVideoId'] ?? null;
                if (!\is_string($provider) || !\is_string($id) || !VideoUrlParser::isProviderId($provider, $id)) {
                    continue;
                }
                $title = trim($content->getTitle());
                $rows[] = [
                    'content_id' => $content->getId()->toRfc4122(),
                    'content_title' => '' !== $title ? $title : 'Başlıksız içerik',
                    'provider' => $provider,
                ];
            }
        }

        return $rows;
    }
}

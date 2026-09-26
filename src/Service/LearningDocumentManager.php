<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\LearningDocumentCard;
use App\Entity\LearningContentRevision;
use App\Entity\LearningDocumentAsset;
use App\Entity\User;
use App\Enum\LearningDocumentStatus;
use App\Enum\UserRole;
use App\Exception\LearningContentException;
use App\LearningContent\Document\LearningDocumentStorage;
use App\LearningContent\Document\PdfDocumentInspector;
use App\Repository\LearningDocumentAssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * PDF receive and admin approval. Does not pretend an antivirus scan ran.
 */
final class LearningDocumentManager
{
    public function __construct(
        private readonly LearningDocumentAssetRepository $assets,
        private readonly LearningDocumentStorage $storage,
        private readonly PdfDocumentInspector $inspector,
        private readonly ActiveVerifiedUserPolicy $activeUsers,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly string $appSecret,
    ) {
    }

    public function receive(User $actor, UploadedFile $file): LearningDocumentAsset
    {
        if (!$this->activeUsers->isActiveAndVerified($actor)) {
            throw LearningContentException::assetInvalid('Doküman yüklemek için hesabın doğrulanmış ve etkin olması gerekir.');
        }
        $bytes = file_get_contents($file->getPathname());
        if (!\is_string($bytes)) {
            throw LearningContentException::assetInvalid(PdfDocumentInspector::MESSAGE_TYPE);
        }
        $name = $this->inspector->inspect($bytes, $file->getClientOriginalName());
        $sha = hash('sha256', $bytes);
        $key = $this->storage->write($bytes);
        try {
            $asset = LearningDocumentAsset::receive(
                $actor,
                $name,
                'application/pdf',
                \strlen($bytes),
                $key,
                $sha,
                \DateTimeImmutable::createFromInterface($this->clock->now()),
            );
            $this->assets->save($asset);
        } catch (\Throwable $e) {
            $this->storage->deleteQuietly($key);
            if ($e instanceof LearningContentException) {
                throw $e;
            }
            throw LearningContentException::assetInvalid('Doküman kaydedilemedi.');
        }

        return $asset;
    }

    public function markReady(User $actor, string $handle): void
    {
        $asset = $this->requireHandle($actor, $handle, true);
        if (!$this->canApprove($actor)) {
            throw LearningContentException::assetInvalid('PDF onayını yalnız yönetici verebilir.');
        }
        $this->assertBytesIntact($asset);
        $asset->markReady(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();
    }

    public function quarantine(User $actor, string $handle): void
    {
        $asset = $this->requireHandle($actor, $handle, true);
        if (!$this->canApprove($actor)) {
            throw LearningContentException::assetInvalid('PDF onayını yalnız yönetici verebilir.');
        }
        $this->assertNotReferencedBySealedRevision($asset);
        $asset->quarantine(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();
    }

    public function archive(User $actor, string $handle): void
    {
        $asset = $this->requireHandle($actor, $handle, true);
        if (!$this->canApprove($actor)) {
            throw LearningContentException::assetInvalid('PDF onayını yalnız yönetici verebilir.');
        }
        $this->assertNotReferencedBySealedRevision($asset);
        $asset->archive(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();
    }

    public function requireReadyAsset(Uuid $id): LearningDocumentAsset
    {
        $asset = $this->entityManager->find(LearningDocumentAsset::class, $id);
        if (!$asset instanceof LearningDocumentAsset || !$asset->isServable()) {
            throw LearningContentException::notFound();
        }
        $this->assertBytesIntact($asset);

        return $asset;
    }

    public function requireReadable(User $actor, string $handle): LearningDocumentAsset
    {
        $asset = $this->requireHandle($actor, $handle, $this->canApprove($actor));
        if (!$asset->isServable() && LearningDocumentStatus::Pending !== $asset->getStatus()) {
            throw LearningContentException::notFound();
        }
        $this->assertBytesIntact($asset);

        return $asset;
    }

    public function requireAttachable(User $actor, string $handle): LearningDocumentAsset
    {
        $asset = $this->requireHandle($actor, $handle, $this->canApprove($actor));
        if (!$asset->isServable()) {
            throw LearningContentException::assetInvalid('Onaysız doküman sürüme bağlanamaz.');
        }
        if (!$this->canApprove($actor) && !$asset->getCreatedBy()->getId()->equals($actor->getId())) {
            throw LearningContentException::notFound();
        }
        $this->assertBytesIntact($asset);

        return $asset;
    }

    /**
     * @return list<LearningDocumentCard>
     */
    public function cardsFor(User $actor): array
    {
        $admin = $this->canApprove($actor);
        $cards = [];
        foreach ($this->assets->findCandidates($actor, $admin) as $asset) {
            if (!$admin && !$asset->getCreatedBy()->getId()->equals($actor->getId())) {
                continue;
            }
            $cards[] = new LearningDocumentCard(
                $asset->getOriginalName(),
                PdfDocumentInspector::sizeLabel($asset->getByteSize()),
                $this->statusLabel($asset->getStatus()),
                $this->handle($asset->getId()),
                $admin && LearningDocumentStatus::Pending === $asset->getStatus(),
                $asset->isServable(),
            );
        }

        return $cards;
    }

    public function assertReadyAssets(LearningContentRevision $revision): void
    {
        foreach ($this->assetIds($revision->getStructuredContent()) as $id) {
            $asset = $this->entityManager->find(LearningDocumentAsset::class, $id);
            if (!$asset instanceof LearningDocumentAsset || !$asset->isServable()) {
                throw LearningContentException::assetInvalid('Onaysız doküman yayımlanamaz.');
            }
            $this->assertBytesIntact($asset);
        }
    }

    private function requireHandle(User $actor, string $handle, bool $asAdmin): LearningDocumentAsset
    {
        foreach ($this->assets->findCandidates($actor, $asAdmin) as $asset) {
            if (hash_equals($this->handle($asset->getId()), $handle)) {
                return $asset;
            }
        }

        throw LearningContentException::notFound();
    }

    private function handle(Uuid $id): string
    {
        return hash_hmac('sha256', $id->toRfc4122(), $this->appSecret);
    }

    private function canApprove(User $actor): bool
    {
        if (!$this->activeUsers->isActiveAndVerified($actor)) {
            return false;
        }
        $roles = $actor->getRoles();

        return \in_array(UserRole::SuperAdmin->value, $roles, true)
            || \in_array(UserRole::Admin->value, $roles, true);
    }

    private function assertBytesIntact(LearningDocumentAsset $asset): void
    {
        $this->storage->read($asset->getStorageKey(), $asset->getContentSha256());
    }

    private function assertNotReferencedBySealedRevision(LearningDocumentAsset $asset): void
    {
        $needle = $asset->getId()->toRfc4122();
        $count = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM learning_content_revisions WHERE sealed_at IS NOT NULL AND structured_content LIKE ?',
            ['%'.$needle.'%'],
        );
        if ($count > 0) {
            throw LearningContentException::assetInvalid('Yayımlanmış sürümün dokümanı değiştirilemez.');
        }
    }

    /**
     * @param array<string, mixed> $structured
     *
     * @return list<Uuid>
     */
    private function assetIds(array $structured): array
    {
        $blocks = $structured['blocks'] ?? [];
        if (!\is_array($blocks)) {
            return [];
        }
        $ids = [];
        foreach ($blocks as $block) {
            if (!\is_array($block) || 'document' !== ($block['type'] ?? null)) {
                continue;
            }
            $id = $block['assetId'] ?? null;
            if (\is_string($id) && Uuid::isValid($id)) {
                $ids[] = Uuid::fromString($id);
            }
        }

        return $ids;
    }

    private function statusLabel(LearningDocumentStatus $status): string
    {
        return match ($status) {
            LearningDocumentStatus::Pending => 'Onay bekliyor',
            LearningDocumentStatus::Ready => 'Kullanıma hazır',
            LearningDocumentStatus::Quarantined => 'Karantina',
            LearningDocumentStatus::Archived => 'Arşiv',
        };
    }
}

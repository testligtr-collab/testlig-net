<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\StoredMediaAsset;
use App\Entity\User;
use App\Enum\SecurityAuditAction;
use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\StoredMediaScanStatus;
use App\Enum\StoredMediaStorageProvider;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\LearningContentException;
use App\Question\Image\Scan\QuestionImageScanVerdict;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\StoredMediaAssetManager;
use App\Service\UserFactory;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class QuestionImageScanCommitDbalTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        QuestionBankDbCleanup::deleteTables($this->em->getConnection());
    }

    protected function tearDown(): void
    {
        $connection = $this->em->isOpen() ? $this->em->getConnection() : null;
        if ($connection instanceof \Doctrine\DBAL\Connection) {
            QuestionBankDbCleanup::deleteTables($connection);
        }
        parent::tearDown();
    }

    public function testCleanAndReadyAuditsCommitTogether(): void
    {
        $actor = $this->superAdmin('scan-commit-ok@example.com');
        $media = $this->media();
        $asset = $this->register($media, $actor);
        $readyBefore = $this->auditCount(SecurityAuditAction::StoredMediaAssetMarkedReady->value);
        $scanBefore = $this->auditCount(SecurityAuditAction::StoredMediaAssetScanUpdated->value);

        $media->commitQuestionImageScan($actor, $media->describeQuestionImage($asset->getId()), QuestionImageScanVerdict::Clean);

        $this->em->clear();
        $stored = $this->em->find(StoredMediaAsset::class, $asset->getId());
        self::assertInstanceOf(StoredMediaAsset::class, $stored);
        self::assertSame(StoredMediaAssetStatus::Ready, $stored->getStatus());
        self::assertSame(StoredMediaScanStatus::Clean, $stored->getScanStatus());
        self::assertSame($readyBefore + 1, $this->auditCount(SecurityAuditAction::StoredMediaAssetMarkedReady->value));
        self::assertSame($scanBefore + 1, $this->auditCount(SecurityAuditAction::StoredMediaAssetScanUpdated->value));
    }

    public function testAuditFlushFailureRollsBackCleanAndReady(): void
    {
        $actor = $this->superAdmin('scan-commit-rollback@example.com');
        $media = $this->media();
        $asset = $this->register($media, $actor);
        $assetId = $asset->getId();
        $snapshot = $media->describeQuestionImage($assetId);
        $readyBefore = $this->auditCount(SecurityAuditAction::StoredMediaAssetMarkedReady->value);
        $scanBefore = $this->auditCount(SecurityAuditAction::StoredMediaAssetScanUpdated->value);
        $this->em->getEventManager()->addEventListener('onFlush', new FlushFailureListener());

        try {
            $media->commitQuestionImageScan($actor, $snapshot, QuestionImageScanVerdict::Clean);
            self::fail('A failed flush must not leave a ready asset.');
        } catch (\RuntimeException $exception) {
            self::assertSame('audit-flush-failed', $exception->getMessage());
        }

        $this->reopen();
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT status, scan_status FROM stored_media_assets WHERE id = ?',
            [$assetId->toBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsArray($row);
        self::assertSame('pending', $row['status']);
        self::assertSame('pending', $row['scan_status']);
        self::assertSame($readyBefore, $this->auditCount(SecurityAuditAction::StoredMediaAssetMarkedReady->value));
        self::assertSame($scanBefore, $this->auditCount(SecurityAuditAction::StoredMediaAssetScanUpdated->value));
    }

    public function testConcurrentStatusChangeIsNotOverwritten(): void
    {
        $actor = $this->superAdmin('scan-commit-race@example.com');
        $media = $this->media();
        $asset = $this->register($media, $actor);
        $snapshot = $media->describeQuestionImage($asset->getId());
        $media->markScanStatus($asset, $actor, StoredMediaScanStatus::Failed, 'question_image_scan');
        $readyBefore = $this->auditCount(SecurityAuditAction::StoredMediaAssetMarkedReady->value);

        try {
            $media->commitQuestionImageScan($actor, $snapshot, QuestionImageScanVerdict::Clean);
            self::fail('A changed scan row must not become ready.');
        } catch (LearningContentException $exception) {
            self::assertSame('conflict', $exception->getReason()->value);
        }

        $this->em->clear();
        $stored = $this->em->find(StoredMediaAsset::class, $asset->getId());
        self::assertInstanceOf(StoredMediaAsset::class, $stored);
        self::assertSame(StoredMediaAssetStatus::Pending, $stored->getStatus());
        self::assertSame(StoredMediaScanStatus::Failed, $stored->getScanStatus());
        self::assertSame($readyBefore, $this->auditCount(SecurityAuditAction::StoredMediaAssetMarkedReady->value));
    }

    private function register(StoredMediaAssetManager $media, User $actor): StoredMediaAsset
    {
        return $media->registerMetadata(
            $actor,
            StoredMediaAssetScope::Platform,
            null,
            StoredMediaAssetKind::Image,
            StoredMediaStorageProvider::Local,
            'soru.png',
            'image/png',
            4,
            hash('sha256', 'aaaa'),
            'question_image_upload',
        );
    }

    private function superAdmin(string $email): User
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $user = $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ada', 'Admin', UserRole::Student);
        $user->addGlobalRole(UserRole::SuperAdmin);
        $user->markEmailVerified(new \DateTimeImmutable('2026-10-14T12:00:00Z'));
        $user->transitionTo(UserStatus::Active);
        $users->save($user);

        return $user;
    }

    private function media(): StoredMediaAssetManager
    {
        $media = static::getContainer()->get(StoredMediaAssetManager::class);
        self::assertInstanceOf(StoredMediaAssetManager::class, $media);

        return $media;
    }

    private function auditCount(string $action): int
    {
        $events = static::getContainer()->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);

        return $events->countByAction($action);
    }

    private function reopen(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }
}

final class FlushFailureListener
{
    public function onFlush(): void
    {
        throw new \RuntimeException('audit-flush-failed');
    }
}

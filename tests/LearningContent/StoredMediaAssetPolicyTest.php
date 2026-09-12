<?php

declare(strict_types=1);

namespace App\Tests\LearningContent;

use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Exception\LearningContentException;
use App\LearningContent\Media\StorageKeyFactory;
use App\LearningContent\Media\StoredMediaAssetPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class StoredMediaAssetPolicyTest extends TestCase
{
    private StoredMediaAssetPolicy $policy;
    private StorageKeyFactory $keys;

    protected function setUp(): void
    {
        $this->policy = new StoredMediaAssetPolicy();
        $this->keys = new StorageKeyFactory();
    }

    public function testAcceptsValidImageRegistration(): void
    {
        $sha = str_repeat('ab', 32);
        $this->policy->assertValidRegistration(
            StoredMediaAssetKind::Image,
            'image/png',
            'cover.png',
            1024,
            $sha,
        );
        self::assertSame(10_485_760, $this->policy->maxBytesFor(StoredMediaAssetKind::Image));
    }

    public function testRejectsDisallowedMime(): void
    {
        $this->expectException(LearningContentException::class);
        $this->policy->assertValidRegistration(
            StoredMediaAssetKind::Image,
            'application/x-msdownload',
            'cover.png',
            1024,
            str_repeat('ab', 32),
        );
    }

    public function testRejectsOversize(): void
    {
        $this->expectException(LearningContentException::class);
        $this->policy->assertValidRegistration(
            StoredMediaAssetKind::Image,
            'image/png',
            'cover.png',
            50_000_000,
            str_repeat('ab', 32),
        );
    }

    public function testRejectsTraversalFilename(): void
    {
        $this->expectException(LearningContentException::class);
        $this->policy->assertValidRegistration(
            StoredMediaAssetKind::Image,
            'image/png',
            '../cover.png',
            1024,
            str_repeat('ab', 32),
        );
    }

    public function testRejectsInvalidChecksum(): void
    {
        $this->expectException(LearningContentException::class);
        $this->policy->assertValidRegistration(
            StoredMediaAssetKind::Image,
            'image/png',
            'cover.png',
            1024,
            'NOTHEX',
        );
    }

    public function testStorageKeyFactoryBuildsScopedKey(): void
    {
        $id = new UuidV7();
        $sha = str_repeat('cd', 32);
        $key = $this->keys->create(StoredMediaAssetScope::Platform, null, StoredMediaAssetKind::Image, $id, $sha);
        self::assertStringStartsWith('media/platform/image/', $key);
    }

    public function testStorageKeyFactoryRejectsAbsolutePath(): void
    {
        $this->expectException(LearningContentException::class);
        $this->keys->assertSafeKey('/etc/passwd');
    }

    public function testStorageKeyFactoryRejectsTraversal(): void
    {
        $this->expectException(LearningContentException::class);
        $this->keys->assertSafeKey('media/../secret');
    }

    public function testStorageKeyFactoryRejectsWindowsPath(): void
    {
        $this->expectException(LearningContentException::class);
        $this->keys->assertSafeKey('C:\\windows\\system32');
    }
}

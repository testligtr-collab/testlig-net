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

    public function testRejectsMimeSizePathTraversalAndChecksum(): void
    {
        $sha = str_repeat('ab', 32);

        try {
            $this->policy->assertValidRegistration(
                StoredMediaAssetKind::Image,
                'application/x-msdownload',
                'cover.png',
                1024,
                $sha,
            );
            self::fail('bad mime');
        } catch (LearningContentException) {
            self::assertTrue(true);
        }

        try {
            $this->policy->assertValidRegistration(
                StoredMediaAssetKind::Image,
                'image/png',
                'cover.png',
                50_000_000,
                $sha,
            );
            self::fail('oversize');
        } catch (LearningContentException) {
            self::assertTrue(true);
        }

        try {
            $this->policy->assertValidRegistration(
                StoredMediaAssetKind::Image,
                'image/png',
                '../cover.png',
                1024,
                $sha,
            );
            self::fail('traversal filename');
        } catch (LearningContentException) {
            self::assertTrue(true);
        }

        try {
            $this->policy->assertValidRegistration(
                StoredMediaAssetKind::Image,
                'image/png',
                'cover.png',
                1024,
                'NOTHEX',
            );
            self::fail('bad sha');
        } catch (LearningContentException) {
            self::assertTrue(true);
        }
    }

    public function testStorageKeyFactoryRejectsAbsoluteAndTraversal(): void
    {
        $id = new UuidV7();
        $sha = str_repeat('cd', 32);
        $key = $this->keys->create(StoredMediaAssetScope::Platform, null, StoredMediaAssetKind::Image, $id, $sha);
        self::assertStringStartsWith('media/platform/image/', $key);

        try {
            $this->keys->assertSafeKey('/etc/passwd');
            self::fail('absolute');
        } catch (LearningContentException) {
            self::assertTrue(true);
        }

        try {
            $this->keys->assertSafeKey('media/../secret');
            self::fail('traversal');
        } catch (LearningContentException) {
            self::assertTrue(true);
        }

        try {
            $this->keys->assertSafeKey('C:\\windows\\system32');
            self::fail('windows path');
        } catch (LearningContentException) {
            self::assertTrue(true);
        }
    }
}

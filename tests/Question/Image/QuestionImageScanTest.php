<?php

declare(strict_types=1);

namespace App\Tests\Question\Image;

use App\Entity\User;
use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\StoredMediaScanStatus;
use App\Enum\StoredMediaStorageProvider;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\LearningContentException;
use App\Exception\QuestionException;
use App\LearningContent\Document\BunnyStorageConfiguration;
use App\LearningContent\Media\StorageKeyFactory;
use App\Question\Content\QuestionPlainText;
use App\Question\Image\BunnyQuestionImageClient;
use App\Question\Image\LocalQuestionImageStore;
use App\Question\Image\QuestionImageByteStore;
use App\Question\Image\QuestionImageInspector;
use App\Question\Image\QuestionImageObjectKey;
use App\Question\Image\QuestionImageStoreResolver;
use App\Question\Image\Scan\ClamdInstreamClient;
use App\Question\Image\Scan\QuestionImageScanCommit;
use App\Question\Image\Scan\QuestionImageScanCopy;
use App\Question\Image\Scan\QuestionImageScanner;
use App\Question\Image\Scan\QuestionImageScanRecords;
use App\Question\Image\Scan\QuestionImageScanSnapshot;
use App\Question\Image\Scan\QuestionImageScanVerdict;
use App\Service\ActiveVerifiedUserPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class QuestionImageScanTest extends TestCase
{
    public function testCleanMarksTheAssetReadyInOneSequence(): void
    {
        [$asset, $actor] = $this->pendingAsset();
        $stem = $this->stem($asset->getId()->toRfc4122());
        $before = $stem;
        $audits = [];
        (new QuestionImageScanCommit())->apply(
            $actor,
            $asset,
            QuestionImageScanSnapshot::from($asset),
            QuestionImageScanVerdict::Clean,
            new \DateTimeImmutable('2026-10-14T12:00:00Z'),
            static function ($action) use (&$audits, $asset): void {
                $audits[] = [$action->value, $asset->getStatus()->value, $asset->getScanStatus()->value];
            },
        );

        self::assertSame([
            ['stored_media_asset_scan_updated', 'pending', 'clean'],
            ['stored_media_asset_marked_ready', 'ready', 'clean'],
        ], $audits);
        self::assertSame(StoredMediaAssetStatus::Ready, $asset->getStatus());
        self::assertSame(StoredMediaScanStatus::Clean, $asset->getScanStatus());
        self::assertSame($before, $stem);
        self::assertSame(['Soru'], QuestionPlainText::lines($stem));
    }

    public function testInfectedQuarantinesAndCannotReturnToClean(): void
    {
        [$asset] = $this->pendingAsset();
        $snapshot = QuestionImageScanSnapshot::from($asset);
        $now = new \DateTimeImmutable('2026-10-14T12:00:00Z');
        (new QuestionImageScanCommit())->apply($this->superAdmin(), $asset, $snapshot, QuestionImageScanVerdict::Infected, $now, static function (): void {
        });

        self::assertSame(StoredMediaAssetStatus::Quarantined, $asset->getStatus());
        self::assertSame(StoredMediaScanStatus::Infected, $asset->getScanStatus());
        try {
            $asset->markScanStatus(StoredMediaScanStatus::Clean, $now);
            self::fail('Infected scan status must stay terminal.');
        } catch (LearningContentException) {
            self::assertSame(StoredMediaScanStatus::Infected, $asset->getScanStatus());
            self::assertSame(StoredMediaAssetStatus::Quarantined, $asset->getStatus());
        }
        try {
            (new QuestionImageScanCommit())->apply($this->superAdmin(), $asset, $snapshot, QuestionImageScanVerdict::Clean, $now, static function (): void {
                self::fail('A changed row must not be audited.');
            });
            self::fail('A changed row must not be overwritten.');
        } catch (LearningContentException $exception) {
            self::assertSame('conflict', $exception->getReason()->value);
        }
    }

    public function testFailuresDoNotMarkReady(): void
    {
        $verdicts = [
            QuestionImageScanVerdict::Error,
            QuestionImageScanVerdict::SizeLimit,
            QuestionImageScanVerdict::Malformed,
            QuestionImageScanVerdict::ConnectionFailed,
            QuestionImageScanVerdict::TimedOut,
            QuestionImageScanVerdict::HashMismatch,
        ];
        foreach ($verdicts as $verdict) {
            [$asset, $actor] = $this->pendingAsset();
            (new QuestionImageScanCommit())->apply(
                $actor,
                $asset,
                QuestionImageScanSnapshot::from($asset),
                $verdict,
                new \DateTimeImmutable('2026-10-14T12:00:00Z'),
                static function (): void {
                },
            );
            self::assertSame(StoredMediaAssetStatus::Pending, $asset->getStatus());
            self::assertSame(StoredMediaScanStatus::Failed, $asset->getScanStatus());
        }
    }

    public function testMetadataChangeAndUnauthorizedActorDoNotWrite(): void
    {
        [$asset, $actor] = $this->pendingAsset();
        $snapshot = QuestionImageScanSnapshot::from($asset);
        $changed = new QuestionImageScanSnapshot(
            $snapshot->assetId,
            $snapshot->scope,
            $snapshot->institutionId,
            $snapshot->kind,
            $snapshot->status,
            $snapshot->scanStatus,
            $snapshot->provider,
            $snapshot->storageKey,
            $snapshot->byteSize,
            str_repeat('ab', 32),
        );
        $now = new \DateTimeImmutable('2026-10-14T12:00:00Z');
        try {
            (new QuestionImageScanCommit())->apply($actor, $asset, $changed, QuestionImageScanVerdict::Clean, $now, static function (): void {
                self::fail('A metadata mismatch must not be audited.');
            });
            self::fail('A metadata mismatch must not be written.');
        } catch (LearningContentException) {
            self::assertSame(StoredMediaScanStatus::Pending, $asset->getScanStatus());
            self::assertSame(StoredMediaAssetStatus::Pending, $asset->getStatus());
        }

        foreach ([UserRole::Teacher, UserRole::Admin] as $role) {
            $user = $this->user($role);
            try {
                (new QuestionImageScanCommit())->apply($user, $asset, $snapshot, QuestionImageScanVerdict::Clean, $now, static function (): void {
                    self::fail('An unauthorized actor must not be audited.');
                });
                self::fail($role->value);
            } catch (LearningContentException $exception) {
                self::assertSame('unauthorized', $exception->getReason()->value);
            }
        }
        self::assertSame(StoredMediaScanStatus::Pending, $asset->getScanStatus());
    }

    public function testScannerCleanCommitsOnlyAfterTheCopyIsRemoved(): void
    {
        $bytes = 'private-image';
        [$snapshot, $copy] = $this->copySnapshot($bytes);
        $records = new MemoryScanRecords($snapshot);
        $store = new MemoryByteStore($copy);
        $transport = new RecordingClamdTransport("stream: OK\n");
        $verdict = $this->scanner($records, $store, $transport)->scanPendingQuestionImage($this->superAdmin(), $snapshot->assetId);

        self::assertSame(QuestionImageScanVerdict::Clean, $verdict);
        self::assertSame(QuestionImageScanVerdict::Clean, $records->verdict);
        self::assertFalse(is_file($copy->path()));
        self::assertSame(0, $store->deletes);
    }

    public function testHashMismatchDoesNotCallTheScanner(): void
    {
        [$snapshot, $copy] = $this->copySnapshot('private-image', str_repeat('cd', 32));
        $records = new MemoryScanRecords($snapshot);
        $transport = new RecordingClamdTransport("stream: OK\n");
        $verdict = $this->scanner($records, new MemoryByteStore($copy), $transport)->scanPendingQuestionImage($this->superAdmin(), $snapshot->assetId);

        self::assertSame(QuestionImageScanVerdict::HashMismatch, $verdict);
        self::assertSame('', $transport->sent);
        self::assertSame(QuestionImageScanVerdict::HashMismatch, $records->verdict);
        self::assertFalse(is_file($copy->path()));
    }

    public function testCleanupFailureDoesNotCommitReady(): void
    {
        [$snapshot, $copy] = $this->copySnapshot('private-image');
        $copy->failDelete = true;
        $records = new MemoryScanRecords($snapshot);
        $verdict = $this->scanner($records, new MemoryByteStore($copy), new RecordingClamdTransport("stream: OK\n"))
            ->scanPendingQuestionImage($this->superAdmin(), $snapshot->assetId);

        self::assertSame(QuestionImageScanVerdict::CleanupFailed, $verdict);
        self::assertNull($records->verdict);
        self::assertTrue(is_file($copy->path()));
        unlink($copy->path());
    }

    public function testConflictLeavesTheCallerUnchanged(): void
    {
        [$snapshot, $copy] = $this->copySnapshot('private-image');
        $records = new MemoryScanRecords($snapshot);
        $records->conflict = true;
        $verdict = $this->scanner($records, new MemoryByteStore($copy), new RecordingClamdTransport("stream: OK\n"))
            ->scanPendingQuestionImage($this->superAdmin(), $snapshot->assetId);

        self::assertSame(QuestionImageScanVerdict::Unchanged, $verdict);
        self::assertSame(0, $records->commits);
    }

    public function testUnauthorizedActorDoesNotRead(): void
    {
        [$snapshot] = $this->copySnapshot('private-image');
        $store = new MemoryByteStore(new MemoryScanCopy('', 1, str_repeat('ab', 32)));
        try {
            $this->scanner(new MemoryScanRecords($snapshot), $store, new RecordingClamdTransport("stream: OK\n"))
                ->scanPendingQuestionImage($this->user(UserRole::Teacher), $snapshot->assetId);
            self::fail('A teacher must not scan.');
        } catch (LearningContentException $exception) {
            self::assertSame('unauthorized', $exception->getReason()->value);
        }
        self::assertSame(0, $store->reads);
    }

    public function testBunnyAssetDoesNotFallBackToLocal(): void
    {
        $bytes = 'private-image';
        $sha = hash('sha256', $bytes);
        $assetId = new UuidV7();
        $key = (new StorageKeyFactory())->create(StoredMediaAssetScope::Platform, null, StoredMediaAssetKind::Image, $assetId, $sha);
        $snapshot = new QuestionImageScanSnapshot(
            $assetId,
            StoredMediaAssetScope::Platform,
            null,
            StoredMediaAssetKind::Image,
            StoredMediaAssetStatus::Pending,
            StoredMediaScanStatus::Pending,
            StoredMediaStorageProvider::Bunny,
            $key,
            \strlen($bytes),
            $sha,
        );
        $store = new MemoryByteStore(new MemoryScanCopy('', \strlen($bytes), $sha));
        $records = new MemoryScanRecords($snapshot);
        $verdict = $this->scanner($records, $store, new RecordingClamdTransport("stream: OK\n"))
            ->scanPendingQuestionImage($this->superAdmin(), $assetId);

        self::assertSame(QuestionImageScanVerdict::Error, $verdict);
        self::assertSame(0, $store->reads);
        self::assertSame(QuestionImageScanVerdict::Error, $records->verdict);
    }

    public function testForeignInstitutionKeyIsRejectedBeforeRead(): void
    {
        $bytes = 'private-image';
        $sha = hash('sha256', $bytes);
        $assetId = new UuidV7();
        $other = new UuidV7();
        $key = (new StorageKeyFactory())->create(StoredMediaAssetScope::Institution, $other, StoredMediaAssetKind::Image, $assetId, $sha);
        $snapshot = new QuestionImageScanSnapshot(
            $assetId,
            StoredMediaAssetScope::Institution,
            new UuidV7(),
            StoredMediaAssetKind::Image,
            StoredMediaAssetStatus::Pending,
            StoredMediaScanStatus::Pending,
            StoredMediaStorageProvider::Local,
            $key,
            \strlen($bytes),
            $sha,
        );
        $store = new MemoryByteStore(new MemoryScanCopy('', \strlen($bytes), $sha));
        try {
            QuestionImageObjectKey::assertForAsset($key, StoredMediaAssetScope::Institution, $snapshot->institutionId);
            self::fail('A foreign institution key must be rejected.');
        } catch (QuestionException $exception) {
            self::assertSame('question_image_scope', $exception->getMessage());
        }
        try {
            $this->scanner(new MemoryScanRecords($snapshot), $store, new RecordingClamdTransport("stream: OK\n"))
                ->scanPendingQuestionImage($this->superAdmin(), $assetId);
            self::fail('A foreign institution key must not be read.');
        } catch (QuestionException $exception) {
            self::assertSame('question_image_scope', $exception->getMessage());
        }
        self::assertSame(0, $store->reads);
    }

    public function testLocalReadChecksTheCapAndLeavesTheDurableFile(): void
    {
        $root = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'testlig-image-'.bin2hex(random_bytes(4));
        $scans = $root.'-scans';
        $store = new LocalQuestionImageStore($root, new NullLogger(), scanDirectory: $scans);
        $bytes = 'local-image-bytes';
        $sha = hash('sha256', $bytes);
        $key = (new StorageKeyFactory())->create(StoredMediaAssetScope::Platform, null, StoredMediaAssetKind::Image, new UuidV7(), $sha);
        $store->write($key, $bytes, 'image/png', $sha);
        $copy = $store->read($key, \strlen($bytes));
        self::assertSame(\strlen($bytes), $copy->byteSize());
        self::assertSame($sha, $copy->sha256());
        $copy->delete();
        self::assertFileExists($root.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, $key));

        try {
            $store->read($key, \strlen($bytes) - 1);
            self::fail('The byte cap must stop the read.');
        } catch (QuestionException $exception) {
            self::assertSame('question_image_limit', $exception->getMessage());
        }
        self::assertSame([], glob($scans.\DIRECTORY_SEPARATOR.'*.scan') ?: []);
        $this->removeTree($root);
        $this->removeTree($scans);
    }

    public function testBunnyReadUsesTheRecordedKeyAndStopsAtTheCap(): void
    {
        $bytes = 'bunny-image';
        $sha = hash('sha256', $bytes);
        $key = (new StorageKeyFactory())->create(StoredMediaAssetScope::Platform, null, StoredMediaAssetKind::Image, new UuidV7(), $sha);
        $calls = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls, $bytes): MockResponse {
            $calls[] = [$method, $url, $options];

            return new MockResponse($bytes, [
                'http_code' => 200,
                'response_headers' => ['content-length' => (string) \strlen($bytes)],
            ]);
        });
        $scans = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'testlig-bunny-scans-'.bin2hex(random_bytes(4));
        $store = new BunnyQuestionImageClient(
            $client,
            new BunnyStorageConfiguration('test-zone', 'test-access-key', BunnyStorageConfiguration::ENDPOINT),
            new NullLogger(),
            $scans,
        );
        $copy = $store->read($key, \strlen($bytes));
        self::assertSame($sha, $copy->sha256());
        $copy->delete();
        self::assertCount(1, $calls);
        self::assertSame('GET', $calls[0][0]);
        self::assertStringContainsString('/'.$key, $calls[0][1]);
        self::assertStringNotContainsString('/pdf/', $calls[0][1]);
        self::assertSame(['AccessKey: test-access-key'], $calls[0][2]['normalized_headers']['accesskey']);
        self::assertSame(0, $calls[0][2]['max_redirects']);

        $blocked = 0;
        $limited = new class($blocked) implements \Symfony\Contracts\HttpClient\HttpClientInterface {
            public function __construct(private int &$reads)
            {
            }

            /**
             * @param array<string, mixed> $options
             */
            public function request(string $method, string $url, array $options = []): \Symfony\Contracts\HttpClient\ResponseInterface
            {
                ++$this->reads;

                return new HeaderOnlyResponse((string) (QuestionImageInspector::MAX_BYTES + 1));
            }

            public function stream(iterable|\Symfony\Contracts\HttpClient\ResponseInterface $responses, ?float $timeout = null): \Symfony\Contracts\HttpClient\ResponseStreamInterface
            {
                throw new \RuntimeException('body was read');
            }

            /**
             * @param array<string, mixed> $options
             */
            public function withOptions(array $options): static
            {
                return $this;
            }
        };
        $limitedStore = new BunnyQuestionImageClient(
            $limited,
            new BunnyStorageConfiguration('test-zone', 'test-access-key', BunnyStorageConfiguration::ENDPOINT),
            new NullLogger(),
            $scans,
        );
        try {
            $limitedStore->read($key, QuestionImageInspector::MAX_BYTES);
            self::fail('A declared oversize body must be rejected.');
        } catch (QuestionException $exception) {
            self::assertSame('question_image_limit', $exception->getMessage());
        }
        self::assertSame(1, $blocked);
        self::assertSame([], glob($scans.\DIRECTORY_SEPARATOR.'*.scan') ?: []);
        $this->removeTree($scans);
    }

    /**
     * @return array{0: \App\Entity\StoredMediaAsset, 1: User}
     */
    private function pendingAsset(): array
    {
        $actor = $this->superAdmin();
        $bytes = 'png-bytes';
        $sha = hash('sha256', $bytes);
        $id = new UuidV7();
        $key = (new StorageKeyFactory())->create(StoredMediaAssetScope::Platform, null, StoredMediaAssetKind::Image, $id, $sha);

        return [\App\Entity\StoredMediaAsset::register(
            StoredMediaAssetScope::Platform,
            null,
            StoredMediaAssetKind::Image,
            StoredMediaStorageProvider::Local,
            $key,
            'soru.png',
            'image/png',
            \strlen($bytes),
            $sha,
            $actor,
            new \DateTimeImmutable('2026-10-14T12:00:00Z'),
            $id,
        ), $actor];
    }

    private function superAdmin(): User
    {
        return $this->user(UserRole::SuperAdmin);
    }

    private function user(UserRole $role): User
    {
        $user = User::create($role->value.'@example.com', $role->value.'@example.com', 'Ada', 'Admin', 'hash', $role);
        $user->transitionTo(UserStatus::Active);
        $user->markEmailVerified(new \DateTimeImmutable('2026-10-14T12:00:00Z'));

        return $user;
    }

    /**
     * @return array{blocks: list<array{type: string, text?: string, mediaId?: string}>}
     */
    private function stem(string $mediaId): array
    {
        return ['blocks' => [
            ['type' => 'paragraph', 'text' => 'Soru'],
            ['type' => 'image_reference', 'mediaId' => $mediaId],
        ]];
    }

    /**
     * @return array{0: QuestionImageScanSnapshot, 1: MemoryScanCopy}
     */
    private function copySnapshot(string $bytes, ?string $recordedSha = null): array
    {
        $sha = hash('sha256', $bytes);
        $path = tempnam(sys_get_temp_dir(), 'scan');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $assetId = new UuidV7();
        $key = (new StorageKeyFactory())->create(StoredMediaAssetScope::Platform, null, StoredMediaAssetKind::Image, $assetId, $sha);
        $snapshot = new QuestionImageScanSnapshot(
            $assetId,
            StoredMediaAssetScope::Platform,
            null,
            StoredMediaAssetKind::Image,
            StoredMediaAssetStatus::Pending,
            StoredMediaScanStatus::Pending,
            StoredMediaStorageProvider::Local,
            $key,
            \strlen($bytes),
            $recordedSha ?? $sha,
        );

        return [$snapshot, new MemoryScanCopy($path, \strlen($bytes), $sha)];
    }

    private function scanner(MemoryScanRecords $records, QuestionImageByteStore $store, RecordingClamdTransport $transport): QuestionImageScanner
    {
        return new QuestionImageScanner(
            new ActiveVerifiedUserPolicy(),
            $records,
            new QuestionImageStoreResolver(
                $store,
                new BunnyQuestionImageClient(new MockHttpClient(), new BunnyStorageConfiguration('', '', BunnyStorageConfiguration::ENDPOINT), new NullLogger()),
                new BunnyStorageConfiguration('', '', BunnyStorageConfiguration::ENDPOINT),
                'local',
            ),
            new ClamdInstreamClient($transport),
            new NullLogger(),
        );
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $names = scandir($root);
        if (!\is_array($names)) {
            return;
        }
        foreach ($names as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $path = $root.\DIRECTORY_SEPARATOR.$name;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($root);
    }
}

final class HeaderOnlyResponse implements \Symfony\Contracts\HttpClient\ResponseInterface
{
    public function __construct(private readonly string $length)
    {
    }

    public function getStatusCode(): int
    {
        return 200;
    }

    public function getHeaders(bool $throw = true): array
    {
        return ['content-length' => [$this->length]];
    }

    public function getContent(bool $throw = true): string
    {
        throw new \RuntimeException('body was read');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        return null;
    }
}

final class MemoryScanRecords implements QuestionImageScanRecords
{
    public int $commits = 0;
    public ?QuestionImageScanVerdict $verdict = null;
    public bool $conflict = false;

    public function __construct(
        private readonly QuestionImageScanSnapshot $snapshot,
    ) {
    }

    public function describeQuestionImage(Uuid $assetId): QuestionImageScanSnapshot
    {
        return $this->snapshot;
    }

    public function commitQuestionImageScan(User $actor, QuestionImageScanSnapshot $expected, QuestionImageScanVerdict $verdict): void
    {
        if ($this->conflict) {
            throw LearningContentException::conflict();
        }
        ++$this->commits;
        $this->verdict = $verdict;
    }
}

final class MemoryByteStore implements QuestionImageByteStore
{
    public int $reads = 0;
    public int $deletes = 0;

    public function __construct(
        private readonly QuestionImageScanCopy $copy,
    ) {
    }

    public function write(string $storageKey, string $bytes, string $mimeType, string $sha256): void
    {
    }

    public function read(string $storageKey, int $maxBytes): QuestionImageScanCopy
    {
        ++$this->reads;

        return $this->copy;
    }

    public function deleteQuietly(string $storageKey): void
    {
        ++$this->deletes;
    }
}

final class MemoryScanCopy implements QuestionImageScanCopy
{
    public bool $failDelete = false;

    public function __construct(
        private readonly string $path,
        private readonly int $size,
        private readonly string $sha256,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function byteSize(): int
    {
        return $this->size;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function delete(): void
    {
        if ($this->failDelete) {
            throw QuestionException::imageRejected('question_image_cleanup');
        }
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}

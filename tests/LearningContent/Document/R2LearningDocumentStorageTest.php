<?php

declare(strict_types=1);

namespace App\Tests\LearningContent\Document;

use App\Entity\LearningDocumentAsset;
use App\Entity\User;
use App\Enum\LearningDocumentStorageProvider;
use App\Enum\UserRole;
use App\Exception\LearningContentException;
use App\LearningContent\Document\BunnyLearningDocumentStorage;
use App\LearningContent\Document\BunnyStorageClient;
use App\LearningContent\Document\BunnyStorageConfiguration;
use App\LearningContent\Document\CloudflareR2ObjectClient;
use App\LearningContent\Document\LearningDocumentByteStore;
use App\LearningContent\Document\LearningDocumentResponse;
use App\LearningContent\Document\LearningDocumentStorage;
use App\LearningContent\Document\LearningDocumentStoreResolver;
use App\LearningContent\Document\LearningDocumentWriteCommit;
use App\LearningContent\Document\PdfDocumentInspector;
use App\LearningContent\Document\R2Configuration;
use App\LearningContent\Document\R2LearningDocumentStorage;
use App\LearningContent\Document\R2ObjectClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

final class R2LearningDocumentStorageTest extends TestCase
{
    public function testUploadSettingChoosesOnlyNewFiles(): void
    {
        $localDir = $this->directory('local');
        $tempDir = $this->directory('temp');
        $local = new LearningDocumentStorage($localDir);
        $fake = new FakeR2ObjectClient();
        $r2 = new R2LearningDocumentStorage($fake, $tempDir, 64);
        $ready = $this->configuration(true);
        $resolver = $this->resolver($local, $r2, $ready, 'r2');

        $upload = $resolver->forUpload();
        self::assertSame(LearningDocumentStorageProvider::R2, $upload->provider());
        self::assertSame($r2, $upload->store());

        $localAsset = $this->asset(LearningDocumentStorageProvider::Local);
        self::assertSame($local, $resolver->forAsset($localAsset));

        $localUpload = $this->resolver($local, $r2, $ready, 'local');
        self::assertSame(LearningDocumentStorageProvider::Local, $localUpload->forUpload()->provider());
        self::assertSame($r2, $localUpload->forAsset($this->asset(LearningDocumentStorageProvider::R2)));
        self::assertSame(0, $fake->puts);
    }

    public function testMissingR2ConfigurationDoesNotFallBackToLocal(): void
    {
        $localDir = $this->directory('local');
        $resolver = $this->resolver(
            new LearningDocumentStorage($localDir),
            new R2LearningDocumentStorage(new FakeR2ObjectClient(), $this->directory('temp'), 64),
            $this->configuration(false),
            'r2',
        );

        try {
            $resolver->forUpload();
            self::fail();
        } catch (LearningContentException $exception) {
            self::assertSame('R2 yapılandırması eksik.', $exception->getMessage());
        }
        self::assertSame([], glob($localDir.\DIRECTORY_SEPARATOR.'*') ?: []);
    }

    public function testPrivateWriteDownloadsOnceAndServesRangeFromVerifiedCopy(): void
    {
        $fake = new FakeR2ObjectClient();
        $tempDir = $this->directory('temp');
        $store = new R2LearningDocumentStorage($fake, $tempDir, 64);
        $pdf = "%PDF-1.4\nrange\n";
        $key = $store->write($pdf);
        self::assertSame(1, $fake->puts);
        self::assertArrayNotHasKey('ACL', $fake->lastPut);
        self::assertSame('application/pdf', $fake->lastPut['ContentType']);

        $sha = hash('sha256', $pdf);
        $bytes = $store->read($key, $sha);
        $path = $store->absolutePath($key);
        self::assertSame($pdf, $bytes);
        self::assertSame(1, $fake->gets);
        $store->read($key, $sha);
        self::assertSame(1, $fake->gets);

        $response = new BinaryFileResponse($path);
        $response->prepare(Request::create('/pdf', 'GET', server: ['HTTP_RANGE' => 'bytes=0-4']));
        self::assertSame(206, $response->getStatusCode());
        ob_start();
        $response->sendContent();
        $partial = ob_get_clean();
        self::assertSame('%PDF-', $partial);

        $asset = $this->asset(LearningDocumentStorageProvider::R2, $key, $sha);
        $resolver = $this->resolver(
            new LearningDocumentStorage($this->directory('local')),
            $store,
            $this->configuration(true),
            'local',
        );
        $inline = (new LearningDocumentResponse($resolver))->inline($asset);
        self::assertInstanceOf(BinaryFileResponse::class, $inline);
        self::assertSame(1, $fake->gets);
        self::assertSame('application/pdf', $inline->headers->get('Content-Type'));
    }

    public function testHashMismatchAndOversizeDeleteTheTemporaryFile(): void
    {
        $key = str_repeat('a', 32);
        $fake = new FakeR2ObjectClient();
        $fake->objects[$key] = '%PDF-wrong';
        $tempDir = $this->directory('temp');
        $store = new R2LearningDocumentStorage($fake, $tempDir, 64);

        try {
            $store->read($key, hash('sha256', '%PDF-right'));
            self::fail();
        } catch (LearningContentException $exception) {
            self::assertSame('Learning content was not found.', $exception->getMessage());
            self::assertStringNotContainsString($key, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertSame([], glob($tempDir.\DIRECTORY_SEPARATOR.'*') ?: []);

        $oversize = new R2LearningDocumentStorage($fake, $tempDir, 8);
        $fake->objects[$key] = str_repeat('b', 9);
        try {
            $oversize->read($key, hash('sha256', $fake->objects[$key]));
            self::fail();
        } catch (LearningContentException $exception) {
            self::assertStringNotContainsString($key, $exception->getMessage());
        }
        self::assertSame([], glob($tempDir.\DIRECTORY_SEPARATOR.'*') ?: []);
        self::assertSame(PdfDocumentInspector::MAX_BYTES, 26_214_400);
    }

    public function testFailedSaveKeepsTheOriginalErrorWhenCleanupFails(): void
    {
        $store = new ThrowingCleanupStore();
        $commit = new LearningDocumentWriteCommit();
        try {
            $commit->commit($store, '%PDF-1.4', static function (): void {
                throw new \RuntimeException('db-failed');
            });
            self::fail();
        } catch (\RuntimeException $exception) {
            self::assertSame('db-failed', $exception->getMessage());
            self::assertSame(1, $store->deletes);
        }
    }

    public function testSweepKeepsYoungAndLockedFiles(): void
    {
        $tempDir = $this->directory('temp');
        $store = new R2LearningDocumentStorage(new FakeR2ObjectClient(), $tempDir, 64);
        $old = $tempDir.\DIRECTORY_SEPARATOR.'old-copy';
        $young = $tempDir.\DIRECTORY_SEPARATOR.'young-copy';
        $locked = $tempDir.\DIRECTORY_SEPARATOR.'locked-copy';
        file_put_contents($old, 'old');
        file_put_contents($young, 'young');
        file_put_contents($locked, 'locked');
        touch($old, time() - 7200);
        touch($locked, time() - 7200);
        $handle = fopen($locked, 'r');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, \LOCK_EX));

        $store->sweepExpired();
        self::assertFileDoesNotExist($old);
        self::assertFileExists($young);
        self::assertFileExists($locked);
        flock($handle, \LOCK_UN);
        fclose($handle);
    }

    public function testRealClientStreamsClosesAndCapsBytesWithoutNetwork(): void
    {
        $key = str_repeat('c', 32);
        $pdf = '%PDF-1.4 streamed';
        $success = new ProbeStream(Utils::streamFor($pdf));
        $sawStream = false;
        $client = $this->clientWithHandler(static function (RequestInterface $request, array $options) use ($success, &$sawStream): mixed {
            $sawStream = true === ($options['stream'] ?? false);
            self::assertStringContainsString('.eu.r2.cloudflarestorage.com', (string) $request->getUri());

            return Create::promiseFor(new Response(200, [
                'Content-Type' => 'application/pdf',
                'Content-Length' => (string) \strlen('%PDF-1.4 streamed'),
            ], $success));
        });
        $chunks = [];
        foreach ($client->readPrivate($key, 64) as $chunk) {
            $chunks[] = $chunk;
        }
        self::assertTrue($sawStream);
        self::assertSame($pdf, implode('', $chunks));
        self::assertTrue($success->wasClosed);
        self::assertGreaterThan(0, $success->bytesRead);

        $oversize = new ProbeStream(Utils::streamFor(str_repeat('x', 20)));
        $oversizeClient = $this->clientWithHandler(static fn (): mixed => Create::promiseFor(new Response(200, [
            'Content-Length' => '20',
        ], $oversize)));
        try {
            foreach ($oversizeClient->readPrivate($key, 8) as $ignored) {
            }
            self::fail();
        } catch (LearningContentException $exception) {
            self::assertSame('Learning content was not found.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString($key, $exception->getMessage());
        }
        self::assertTrue($oversize->wasClosed);
        self::assertSame(0, $oversize->bytesRead);

        $wrongLength = new ProbeStream(Utils::streamFor(str_repeat('y', 20)));
        $wrongClient = $this->clientWithHandler(static fn (): mixed => Create::promiseFor(new Response(200, [
            'Content-Length' => '4',
        ], $wrongLength)));
        try {
            foreach ($wrongClient->readPrivate($key, 8) as $ignored) {
            }
            self::fail();
        } catch (LearningContentException $exception) {
            self::assertStringNotContainsString($key, $exception->getMessage());
        }
        self::assertTrue($wrongLength->wasClosed);
        self::assertLessThan(20, $wrongLength->bytesRead);
        self::assertLessThanOrEqual(9, $wrongLength->bytesRead);

        $failed = new ProbeStream(Utils::streamFor('error-body'));
        $failedClient = $this->clientWithHandler(static function () use ($failed, $key): mixed {
            $response = new Response(500, [], $failed);

            return Create::rejectionFor([
                'exception' => new \RuntimeException('credential-should-not-leak '.$key.' bucket-private endpoint.eu'),
                'response' => $response,
            ]);
        });
        try {
            foreach ($failedClient->readPrivate($key, 64) as $ignored) {
            }
            self::fail();
        } catch (LearningContentException $exception) {
            self::assertSame('Learning content was not found.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('credential-should-not-leak', $exception->getMessage());
            self::assertStringNotContainsString($key, $exception->getMessage());
        }
        self::assertTrue($failed->wasClosed);
    }

    public function testCleanupFailureIsReportedWithoutChangingTheSaveError(): void
    {
        $bucket = 'pdf-private';
        $endpoint = 'eu.r2.cloudflarestorage.com';
        $credential = 'secret-access-key-value';
        $fake = new FakeR2ObjectClient();
        $fake->deleteLeak = $credential.' '.$bucket.' '.$endpoint;
        $logger = new RecordingLogger();
        $store = new R2LearningDocumentStorage($fake, $this->directory('temp'), 64, $logger);
        $commit = new LearningDocumentWriteCommit();
        $writtenKey = '';

        try {
            $commit->commit($store, '%PDF-1.4', static function (string $storageKey) use (&$writtenKey): void {
                $writtenKey = $storageKey;
                throw new \RuntimeException('db-failed');
            });
            self::fail();
        } catch (\RuntimeException $exception) {
            self::assertSame('db-failed', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertNotSame('', $writtenKey);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame(R2LearningDocumentStorage::CLEANUP_FAILED_EVENT, $logger->records[0]['message']);
        $encoded = json_encode($logger->records[0], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($credential, $encoded);
        self::assertStringNotContainsString($writtenKey, $encoded);
        self::assertStringNotContainsString($bucket, $encoded);
        self::assertStringNotContainsString($endpoint, $encoded);
    }

    public function testPrivateCommandUsesTheEuEndpointAndNoPublicAcl(): void
    {
        $configuration = $this->configuration(true);
        $client = new CloudflareR2ObjectClient($configuration);
        $command = $client->privateObjectCommand(str_repeat('b', 32), '%PDF-1.4');
        self::assertSame(['Bucket', 'Key', 'Body', 'ContentType'], array_keys($command));
        self::assertArrayNotHasKey('ACL', $command);
        self::assertStringEndsWith('.eu.r2.cloudflarestorage.com', $configuration->euEndpoint());
        self::assertStringNotContainsString('r2.cloudflarestorage.com', $command['Body']);
    }

    private function configuration(bool $ready): R2Configuration
    {
        return new R2Configuration(
            $ready ? str_repeat('a', 32) : '',
            $ready ? 'access-key' : '',
            $ready ? 'secret-key' : '',
            $ready ? 'pdf-private' : '',
        );
    }

    private function asset(
        LearningDocumentStorageProvider $provider,
        string $key = 'cccccccccccccccccccccccccccccccc',
        string $sha = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
    ): LearningDocumentAsset {
        $user = User::create('pdf@example.com', 'pdf@example.com', 'Pdf', 'User', 'hash', UserRole::Admin);

        return LearningDocumentAsset::receive(
            $user,
            'Notlar.pdf',
            'application/pdf',
            12,
            $key,
            $sha,
            new \DateTimeImmutable('2026-10-12T12:00:00Z'),
            null,
            $provider,
        );
    }

    private function resolver(
        LearningDocumentStorage $local,
        R2LearningDocumentStorage $r2,
        R2Configuration $r2Configuration,
        string $provider,
    ): LearningDocumentStoreResolver {
        return new LearningDocumentStoreResolver(
            $local,
            $r2,
            new BunnyLearningDocumentStorage(
                new BunnyStorageClient(new MockHttpClient(), new BunnyStorageConfiguration('', '', '')),
                $this->directory('bunny-idle'),
                64,
            ),
            $r2Configuration,
            new BunnyStorageConfiguration('', '', ''),
            $provider,
        );
    }

    private function directory(string $name): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'r2-pdf-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);

        return $dir;
    }

    private function clientWithHandler(\Closure $handler): CloudflareR2ObjectClient
    {
        return new CloudflareR2ObjectClient($this->configuration(true), $handler);
    }
}

final class FakeR2ObjectClient implements R2ObjectClient
{
    public int $puts = 0;

    public int $gets = 0;

    /** @var array<string, string> */
    public array $objects = [];

    /** @var array<string, string> */
    public array $lastPut = [];

    public string $deleteLeak = '';

    public function putPrivate(string $key, string $bytes): void
    {
        ++$this->puts;
        $this->objects[$key] = $bytes;
        $this->lastPut = [
            'ContentType' => 'application/pdf',
        ];
    }

    public function readPrivate(string $key, int $maxBytes): iterable
    {
        ++$this->gets;
        if (!isset($this->objects[$key])) {
            throw new \RuntimeException('missing-object');
        }
        $bytes = $this->objects[$key];
        $offset = 0;
        $length = \strlen($bytes);
        while ($offset < $length) {
            $chunk = substr($bytes, $offset, 4);
            $offset += \strlen($chunk);
            if ($offset > $maxBytes) {
                yield $chunk;
                break;
            }
            yield $chunk;
        }
    }

    public function delete(string $key): void
    {
        if ('' !== $this->deleteLeak) {
            throw new \RuntimeException($this->deleteLeak.' '.$key);
        }
        unset($this->objects[$key]);
    }
}

final class ProbeStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    public bool $wasClosed = false;

    public int $bytesRead = 0;

    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    public function close(): void
    {
        $this->wasClosed = true;
        $this->stream->close();
    }

    public function read(int $length): string
    {
        $chunk = $this->stream->read($length);
        $this->bytesRead += \strlen($chunk);

        return $chunk;
    }
}

final class RecordingLogger implements LoggerInterface
{
    use \Psr\Log\LoggerTrait;

    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

final class ThrowingCleanupStore implements LearningDocumentByteStore
{
    public int $deletes = 0;

    public function write(string $bytes): string
    {
        return str_repeat('e', 32);
    }

    public function read(string $storageKey, string $expectedSha256): string
    {
        return '';
    }

    public function absolutePath(string $storageKey): string
    {
        return $storageKey;
    }

    public function deleteQuietly(string $storageKey): void
    {
        ++$this->deletes;
        throw new \RuntimeException('cleanup-failed-secret');
    }
}

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
use App\LearningContent\Document\LearningDocumentStorage;
use App\LearningContent\Document\LearningDocumentStoreResolver;
use App\LearningContent\Document\LearningDocumentWriteCommit;
use App\LearningContent\Document\R2Configuration;
use App\LearningContent\Document\R2LearningDocumentStorage;
use App\LearningContent\Document\R2ObjectClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class BunnyLearningDocumentStorageTest extends TestCase
{
    public function testUploadSettingKeepsExistingProviders(): void
    {
        $localDir = $this->directory('local');
        $http = new MockHttpClient();
        $local = new LearningDocumentStorage($localDir);
        $bunny = $this->storage($http, $this->readyConfiguration());
        $r2 = new R2LearningDocumentStorage(new IdleR2Client(), $this->directory('r2'), 64);
        $resolver = $this->resolver($local, $r2, $bunny, $this->readyConfiguration(), 'bunny');

        self::assertSame(LearningDocumentStorageProvider::Bunny, $resolver->forUpload()->provider());
        self::assertSame($bunny, $resolver->forUpload()->store());
        self::assertSame($local, $resolver->forAsset($this->asset(LearningDocumentStorageProvider::Local)));
        self::assertSame($r2, $resolver->forAsset($this->asset(LearningDocumentStorageProvider::R2)));
        self::assertSame(LearningDocumentStorageProvider::Local, $this->resolver($local, $r2, $bunny, $this->readyConfiguration(), 'local')->forUpload()->provider());
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testMissingConfigurationDoesNotFallBackToLocal(): void
    {
        $localDir = $this->directory('local');
        $http = new MockHttpClient();
        $resolver = $this->resolver(
            new LearningDocumentStorage($localDir),
            new R2LearningDocumentStorage(new IdleR2Client(), $this->directory('r2'), 64),
            $this->storage($http, new BunnyStorageConfiguration('', '', '')),
            new BunnyStorageConfiguration('', '', ''),
            'bunny',
        );

        try {
            $resolver->forUpload();
            self::fail();
        } catch (LearningContentException $exception) {
            self::assertSame('Bunny yapılandırması eksik.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertSame([], glob($localDir.\DIRECTORY_SEPARATOR.'*') ?: []);
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testPutSendsChecksumAndRejectsRedirectsAndOtherHosts(): void
    {
        $pdf = '%PDF-1.4 bunny';
        $created = new ScriptedHttpResponse(201, '');
        $http = new ScriptedHttpClient($created);
        $store = $this->storage($http, $this->readyConfiguration());
        $key = $store->write($pdf);

        self::assertSame(1, $http->requests);
        self::assertSame('PUT', $http->calls[0][0]);
        self::assertSame('https://storage.bunnycdn.com/pdf-private/pdf/'.$key, $http->calls[0][1]);
        self::assertSame(0, $http->calls[0][2]['max_redirects']);
        self::assertSame($pdf, $http->calls[0][2]['body']);
        self::assertSame('test-access-key', $http->calls[0][2]['headers']['AccessKey']);
        self::assertSame('application/pdf', $http->calls[0][2]['headers']['Content-Type']);
        self::assertSame(strtoupper(hash('sha256', $pdf)), $http->calls[0][2]['headers']['Checksum']);
        self::assertArrayNotHasKey('allowRootDelete', $http->calls[0][2]['headers']);
        self::assertStringNotContainsString('allowRootDelete', $http->calls[0][1]);
        self::assertTrue($created->canceled);

        $redirect = new ScriptedHttpResponse(302, 'secret-body', ['location' => ['https://evil.example/steal']]);
        $redirectClient = new ScriptedHttpClient($redirect);
        $redirectStore = $this->storage($redirectClient, $this->readyConfiguration());
        try {
            $redirectStore->write($pdf);
            self::fail();
        } catch (LearningContentException $exception) {
            self::assertSame('Doküman kaydedilemedi.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('test-access-key', $exception->getMessage());
            self::assertStringNotContainsString('evil.example', $exception->getMessage());
            self::assertStringNotContainsString('secret-body', $exception->getMessage());
        }
        self::assertSame(1, $redirectClient->requests);
        self::assertTrue($redirect->canceled);
        self::assertFalse($redirect->streamOpened);

        $foreign = new ScriptedHttpClient();
        $foreignStore = $this->storage($foreign, new BunnyStorageConfiguration('pdf-private', 'test-access-key', 'https://evil.example'));
        try {
            $foreignStore->write($pdf);
            self::fail();
        } catch (LearningContentException $exception) {
            self::assertSame('Bunny yapılandırması eksik.', $exception->getMessage());
        }
        self::assertSame(0, $foreign->requests);
    }

    public function testReadOnceVerifiesHashAndServesRangeFromTheTemporaryFile(): void
    {
        $pdf = "%PDF-1.4\nrange\n";
        $download = new ScriptedHttpResponse(200, $pdf, ['content-length' => [(string) \strlen($pdf)]]);
        $http = new ScriptedHttpClient(new ScriptedHttpResponse(201, ''), $download);
        $store = $this->storage($http, $this->readyConfiguration());
        $key = $store->write($pdf);
        $sha = hash('sha256', $pdf);
        self::assertSame($pdf, $store->read($key, $sha));
        $path = $store->absolutePath($key);
        $store->read($key, $sha);
        self::assertSame(2, $http->requests);
        self::assertSame('GET', $http->calls[1][0]);
        self::assertTrue($download->streamOpened);
        self::assertTrue($download->canceled);
        self::assertFalse(\is_resource($download->stream));

        $range = new BinaryFileResponse($path);
        $range->prepare(Request::create('/pdf', 'GET', server: ['HTTP_RANGE' => 'bytes=0-4']));
        self::assertSame(206, $range->getStatusCode());
        ob_start();
        $range->sendContent();
        self::assertSame('%PDF-', ob_get_clean());
    }

    public function testHashMismatchOversizeAndStatusFailuresCloseTheResponse(): void
    {
        $key = str_repeat('a', 32);
        $oversize = new ScriptedHttpResponse(200, str_repeat('x', 20), ['content-length' => ['20']]);
        $store = $this->storage(new ScriptedHttpClient($oversize), $this->readyConfiguration(), 8);
        $tempDir = $this->tempOf($store);
        try {
            $store->read($key, hash('sha256', str_repeat('x', 20)));
            self::fail();
        } catch (LearningContentException $exception) {
            self::assertSame('Learning content was not found.', $exception->getMessage());
            self::assertStringNotContainsString($key, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertFalse($oversize->streamOpened);
        self::assertTrue($oversize->canceled);
        self::assertSame([], glob($tempDir.\DIRECTORY_SEPARATOR.'*') ?: []);

        $mismatch = new ScriptedHttpResponse(200, '%PDF-wrong', ['content-length' => ['10']]);
        $mismatchStore = $this->storage(new ScriptedHttpClient($mismatch), $this->readyConfiguration(), 64);
        try {
            $mismatchStore->read($key, hash('sha256', '%PDF-right'));
            self::fail();
        } catch (LearningContentException $exception) {
            self::assertStringNotContainsString($key, $exception->getMessage());
        }
        self::assertTrue($mismatch->streamOpened);
        self::assertTrue($mismatch->canceled);
        self::assertFalse(\is_resource($mismatch->stream));

        $shortLength = new ScriptedHttpResponse(200, str_repeat('y', 20), ['content-length' => ['4']]);
        $shortStore = $this->storage(new ScriptedHttpClient($shortLength), $this->readyConfiguration(), 8);
        try {
            $shortStore->read($key, hash('sha256', str_repeat('y', 20)));
            self::fail();
        } catch (LearningContentException) {
        }
        self::assertTrue($shortLength->streamOpened);
        self::assertTrue($shortLength->canceled);
        self::assertFalse(\is_resource($shortLength->stream));

        $denied = new ScriptedHttpResponse(401, 'secret-body');
        try {
            $this->storage(new ScriptedHttpClient($denied), $this->readyConfiguration(), 64)->read($key, hash('sha256', 'x'));
            self::fail();
        } catch (LearningContentException $exception) {
            self::assertStringNotContainsString('secret-body', $exception->getMessage());
            self::assertStringNotContainsString('pdf-private', $exception->getMessage());
        }
        self::assertFalse($denied->streamOpened);
        self::assertTrue($denied->canceled);
    }

    public function testFailedSaveReportsCleanupWithoutChangingTheOriginalError(): void
    {
        $pdf = '%PDF-1.4';
        $delete = new ScriptedHttpResponse(500, 'secret-body-zone');
        $http = new ScriptedHttpClient(new ScriptedHttpResponse(201, ''), $delete);
        $logger = new BunnyRecordingLogger();
        $store = $this->storage($http, $this->readyConfiguration(), 64, $logger);
        $written = '';
        try {
            (new LearningDocumentWriteCommit())->commit($store, $pdf, static function (string $key) use (&$written): void {
                $written = $key;
                throw new \RuntimeException('db-failed');
            });
            self::fail();
        } catch (\RuntimeException $exception) {
            self::assertSame('db-failed', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertNotSame('', $written);
        self::assertSame('DELETE', $http->calls[1][0]);
        self::assertSame('https://storage.bunnycdn.com/pdf-private/pdf/'.$written, $http->calls[1][1]);
        self::assertTrue($delete->canceled);
        self::assertCount(1, $logger->records);
        self::assertSame(BunnyLearningDocumentStorage::CLEANUP_FAILED_EVENT, $logger->records[0]['message']);
        $encoded = json_encode($logger->records[0], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($written, $encoded);
        self::assertStringNotContainsString('pdf-private', $encoded);
        self::assertStringNotContainsString('storage.bunnycdn.com', $encoded);
        self::assertStringNotContainsString('test-access-key', $encoded);
        self::assertStringNotContainsString('secret-body-zone', $encoded);
    }

    public function testSweepKeepsYoungAndLockedFiles(): void
    {
        $tempDir = $this->directory('sweep');
        $store = new BunnyLearningDocumentStorage(
            new BunnyStorageClient(new MockHttpClient(), $this->readyConfiguration()),
            $tempDir,
            64,
        );
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

    private function storage(HttpClientInterface $http, BunnyStorageConfiguration $configuration, int $maxBytes = 64, ?LoggerInterface $logger = null): BunnyLearningDocumentStorage
    {
        return new BunnyLearningDocumentStorage(new BunnyStorageClient($http, $configuration), $this->directory('temp'), $maxBytes, $logger);
    }

    private function tempOf(BunnyLearningDocumentStorage $store): string
    {
        $property = new \ReflectionProperty(BunnyLearningDocumentStorage::class, 'copies');
        $copies = $property->getValue($store);
        $directory = new \ReflectionProperty($copies, 'tempDirectory');

        return $directory->getValue($copies);
    }

    private function readyConfiguration(): BunnyStorageConfiguration
    {
        return new BunnyStorageConfiguration('pdf-private', 'test-access-key', BunnyStorageConfiguration::ENDPOINT);
    }

    private function resolver(
        LearningDocumentStorage $local,
        R2LearningDocumentStorage $r2,
        BunnyLearningDocumentStorage $bunny,
        BunnyStorageConfiguration $bunnyConfiguration,
        string $provider,
    ): LearningDocumentStoreResolver {
        return new LearningDocumentStoreResolver(
            $local,
            $r2,
            $bunny,
            new R2Configuration('', '', '', ''),
            $bunnyConfiguration,
            $provider,
        );
    }

    private function asset(LearningDocumentStorageProvider $provider): LearningDocumentAsset
    {
        $user = User::create('pdf@example.com', 'pdf@example.com', 'Pdf', 'User', 'hash', UserRole::Admin);

        return LearningDocumentAsset::receive(
            $user,
            'Notlar.pdf',
            'application/pdf',
            12,
            str_repeat('b', 32),
            str_repeat('c', 64),
            new \DateTimeImmutable('2026-10-12T12:00:00Z'),
            null,
            $provider,
        );
    }

    private function directory(string $name): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'bunny-pdf-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);

        return $dir;
    }
}

final class ScriptedHttpClient implements HttpClientInterface
{
    public int $requests = 0;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<ScriptedHttpResponse> */
    private array $queue;

    private int $cursor = 0;

    public function __construct(ScriptedHttpResponse ...$responses)
    {
        $this->queue = array_values($responses);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        ++$this->requests;
        $this->calls[] = [$method, $url, $options];
        $response = $this->queue[$this->cursor] ?? null;
        ++$this->cursor;
        if (!$response instanceof ScriptedHttpResponse) {
            throw new \RuntimeException('Unexpected Bunny request.');
        }

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('The fake Bunny client does not stream multiple responses.');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return $this;
    }
}

final class ScriptedHttpResponse implements ResponseInterface, StreamableInterface
{
    public bool $canceled = false;

    public bool $streamOpened = false;

    /** @var resource|null */
    public $stream;

    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly array $headers = [],
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->headers;
    }

    public function getContent(bool $throw = true): string
    {
        return $this->body;
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
        $this->canceled = true;
        if (\is_resource($this->stream)) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    public function getInfo(?string $type = null): mixed
    {
        return null;
    }

    public function toStream(bool $throw = true)
    {
        $this->streamOpened = true;
        $handle = fopen('php://temp', 'wb+');
        if (false === $handle) {
            throw new \RuntimeException('Temporary stream failed.');
        }
        fwrite($handle, $this->body);
        rewind($handle);
        $this->stream = $handle;

        return $handle;
    }
}

final class IdleR2Client implements R2ObjectClient
{
    public function putPrivate(string $key, string $bytes): void
    {
    }

    public function readPrivate(string $key, int $maxBytes): iterable
    {
        return [];
    }

    public function delete(string $key): void
    {
    }
}

final class BunnyRecordingLogger implements LoggerInterface
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

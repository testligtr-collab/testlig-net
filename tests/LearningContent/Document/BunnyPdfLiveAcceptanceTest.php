<?php

declare(strict_types=1);

namespace App\Tests\LearningContent\Document;

use App\LearningContent\Document\BunnyLearningDocumentStorage;
use App\LearningContent\Document\BunnyPdfLiveAcceptance;
use App\LearningContent\Document\BunnyStorageClient;
use App\LearningContent\Document\BunnyStorageConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class BunnyPdfLiveAcceptanceTest extends TestCase
{
    public function testAcceptanceUsesOneDownloadRangeAndReal404(): void
    {
        $pdf = "%PDF-1.4\n%testlig-bunny-live\n";
        $download = new LiveAcceptResponse(200, $pdf, ['content-length' => [(string) \strlen($pdf)]]);
        $anonymous = new LiveAcceptResponse(401, 'secret-body');
        $cleanup = new LiveAcceptResponse(404, 'secret-not-found-body');
        $http = new LiveAcceptHttpClient(
            new LiveAcceptResponse(201, ''),
            $download,
            $anonymous,
            new LiveAcceptResponse(200, ''),
            $cleanup,
        );
        $report = $this->acceptance($http)->run();

        self::assertSame('accepted', $report['result']);
        self::assertSame(206, $report['range']);
        self::assertSame(401, $report['anonymous']);
        self::assertSame(404, $report['cleanup']);
        self::assertSame(['PUT', 'GET', 'GET', 'DELETE', 'GET'], array_column($http->calls, 0));
        self::assertTrue($download->streamOpened);
        self::assertFalse($anonymous->streamOpened);
        self::assertFalse($cleanup->streamOpened);
        self::assertSame(0, $http->calls[0][2]['max_redirects']);
        self::assertSame(0, $http->calls[2][2]['max_redirects']);
        self::assertArrayNotHasKey('AccessKey', $http->calls[2][2]['headers']);
        self::assertSame('test-access-key', $http->calls[0][2]['headers']['AccessKey']);
        self::assertSame(strtoupper(hash('sha256', $pdf)), $http->calls[0][2]['headers']['Checksum']);
        self::assertStringContainsString('/testlig-pdf-test/pdf/', $http->calls[0][1]);
        $encoded = json_encode($report, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('test-access-key', $encoded);
        self::assertStringNotContainsString('secret-not-found-body', $encoded);
        self::assertStringNotContainsString('Learning content was not found.', $encoded);
    }

    public function testAnonymousSuccessAndRedirectFailWithoutAnotherDelete(): void
    {
        $pdf = "%PDF-1.4\n%testlig-bunny-live\n";
        $http = new LiveAcceptHttpClient(
            new LiveAcceptResponse(201, ''),
            new LiveAcceptResponse(200, $pdf, ['content-length' => [(string) \strlen($pdf)]]),
            new LiveAcceptResponse(302, 'secret-body', ['location' => ['https://evil.example/steal']]),
            new LiveAcceptResponse(200, ''),
            new LiveAcceptResponse(404, ''),
        );
        $report = $this->acceptance($http)->run();

        self::assertSame('failed', $report['result']);
        self::assertSame(302, $report['anonymous']);
        self::assertSame(404, $report['cleanup']);
        self::assertSame(1, \count(array_filter($http->calls, static fn (array $call): bool => 'DELETE' === $call[0])));
        self::assertStringNotContainsString('evil.example', json_encode($report, \JSON_THROW_ON_ERROR));
    }

    public function testCleanupStatusOtherThan404IsNotVerifiedAndIsNotRetried(): void
    {
        $pdf = "%PDF-1.4\n%testlig-bunny-live\n";
        $http = new LiveAcceptHttpClient(
            new LiveAcceptResponse(201, ''),
            new LiveAcceptResponse(200, $pdf, ['content-length' => [(string) \strlen($pdf)]]),
            new LiveAcceptResponse(401, ''),
            new LiveAcceptResponse(500, 'secret-body-zone'),
            new LiveAcceptResponse(200, $pdf),
        );
        $report = $this->acceptance($http)->run();

        self::assertSame('cleanup_unverified', $report['result']);
        self::assertSame(200, $report['cleanup']);
        self::assertSame(1, \count(array_filter($http->calls, static fn (array $call): bool => 'DELETE' === $call[0])));
        self::assertStringNotContainsString('secret-body-zone', json_encode($report, \JSON_THROW_ON_ERROR));
    }

    public function testTransportFailureIsNotAMissingObject(): void
    {
        $pdf = "%PDF-1.4\n%testlig-bunny-live\n";
        $http = new LiveAcceptHttpClient(
            new LiveAcceptResponse(201, ''),
            new LiveAcceptResponse(200, $pdf, ['content-length' => [(string) \strlen($pdf)]]),
            new LiveAcceptResponse(401, ''),
            new LiveAcceptResponse(200, ''),
        );
        $http->throwAt = 5;
        $report = $this->acceptance($http)->run();

        self::assertSame('cleanup_unverified', $report['result']);
        self::assertSame(0, $report['cleanup']);
        self::assertSame(1, \count(array_filter($http->calls, static fn (array $call): bool => 'DELETE' === $call[0])));
        self::assertStringNotContainsString('secret-transport-body', json_encode($report, \JSON_THROW_ON_ERROR));
    }

    public function testFailedWriteDoesNotDelete(): void
    {
        $http = new LiveAcceptHttpClient(new LiveAcceptResponse(400, 'secret-body'));
        $report = $this->acceptance($http)->run();

        self::assertSame('failed', $report['result']);
        self::assertSame(0, $report['cleanup']);
        self::assertSame(['PUT'], array_column($http->calls, 0));
    }

    public function testScriptStaysDisarmedAndDoesNotReadASecretFile(): void
    {
        $script = (string) file_get_contents(\dirname(__DIR__, 3).'/bin/bunny-pdf-live-accept.php');
        self::assertStringNotContainsString('Kernel', $script);
        self::assertStringNotContainsString('dotenv', $script);
        self::assertStringNotContainsString('DATABASE_URL', $script);
        self::assertStringNotContainsString('.env', $script);
        self::assertStringContainsString('BUNNY_PDF_LIVE_ACCEPT', $script);
        self::assertStringContainsString('BunnyPdfLiveAcceptance::ZONE', $script);

        $disarmed = $this->runScript(['BUNNY_PDF_LIVE_ACCEPT' => '0']);
        self::assertSame(2, $disarmed['exit']);
        self::assertSame("live-accept-not-armed\n", $disarmed['stderr']);

        $missing = $this->runScript(['BUNNY_PDF_LIVE_ACCEPT' => '1']);
        self::assertSame(2, $missing['exit']);
        self::assertSame("missing-access-key\n", $missing['stderr']);
    }

    private function acceptance(LiveAcceptHttpClient $http): BunnyPdfLiveAcceptance
    {
        $configuration = new BunnyStorageConfiguration(
            BunnyPdfLiveAcceptance::ZONE,
            'test-access-key',
            BunnyStorageConfiguration::ENDPOINT,
        );
        $client = new BunnyStorageClient($http, $configuration);
        $temp = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'bunny-live-accept-'.bin2hex(random_bytes(4));
        mkdir($temp, 0700, true);

        return new BunnyPdfLiveAcceptance(new BunnyLearningDocumentStorage($client, $temp), $client);
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array{exit: int, stderr: string, stdout: string}
     */
    private function runScript(array $extra): array
    {
        $env = [];
        foreach (['PATH', 'Path', 'PATHEXT', 'SystemRoot', 'TEMP', 'TMP', 'WINDIR'] as $name) {
            $value = getenv($name);
            if (\is_string($value)) {
                $env[$name] = $value;
            }
        }
        $process = proc_open(
            [\PHP_BINARY, \dirname(__DIR__, 3).\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'bunny-pdf-live-accept.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [...$env, ...$extra],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stderr' => \is_string($stderr) ? $stderr : '',
            'stdout' => \is_string($stdout) ? $stdout : '',
        ];
    }
}

final class LiveAcceptHttpClient implements HttpClientInterface
{
    public int $requests = 0;

    public int $throwAt = 0;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<LiveAcceptResponse> */
    private array $queue;

    private int $cursor = 0;

    public function __construct(LiveAcceptResponse ...$responses)
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
        if ($this->requests === $this->throwAt) {
            throw new \RuntimeException('secret-transport-body');
        }
        $response = $this->queue[$this->cursor] ?? null;
        ++$this->cursor;
        if (!$response instanceof LiveAcceptResponse) {
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

final class LiveAcceptResponse implements ResponseInterface, StreamableInterface
{
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

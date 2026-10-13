<?php

declare(strict_types=1);

namespace App\Tests\Question\Image;

use App\Entity\User;
use App\Enum\QuestionScope;
use App\Enum\StoredMediaStorageProvider;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\QuestionException;
use App\LearningContent\Document\BunnyStorageConfiguration;
use App\LearningContent\Media\StorageKeyFactory;
use App\Question\Content\QuestionPlainText;
use App\Question\Image\BunnyQuestionImageClient;
use App\Question\Image\QuestionImageAuthorizer;
use App\Question\Image\QuestionImageByteStore;
use App\Question\Image\QuestionImageInspector;
use App\Question\Image\QuestionImageMetadataPort;
use App\Question\Image\QuestionImageRegistrar;
use App\Question\Image\QuestionImageStoreResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Uid\Uuid;

final class QuestionImageInspectorTest extends TestCase
{
    public function testAcceptsJpegPngAndWebpAndRejectsUnsafeImages(): void
    {
        if (!\function_exists('imagewebp') || !\function_exists('imagejpeg') || !\function_exists('imagepng')) {
            self::markTestSkipped('GD JPEG, PNG, and WebP support is required.');
        }
        $inspector = new QuestionImageInspector();
        $png = $this->png(8, 8);
        $jpeg = $this->jpeg(8, 8);
        $webp = $this->webp(8, 8);
        self::assertSame('image/png', $inspector->inspect($png, 'image/png')['mime']);
        self::assertSame('image/jpeg', $inspector->inspect($jpeg, 'image/jpeg')['mime']);
        self::assertSame('image/webp', $inspector->inspect($webp, 'image/webp')['mime']);

        $wide = $this->png(4097, 1);
        $pixels = $this->png(4000, 4001);
        $this->assertCode('question_image_decode', static fn () => $inspector->inspect(substr($png, 0, (int) (\strlen($png) / 2)), 'image/png'));
        $this->assertCode('question_image_type', static fn () => $inspector->inspect($png, 'image/jpeg'));
        $this->assertCode('question_image_limit', static fn () => $inspector->inspect(str_repeat('A', QuestionImageInspector::MAX_BYTES + 1), null));
        $this->assertCode('question_image_limit', static fn () => $inspector->inspect($wide, 'image/png'));
        $this->assertCode('question_image_limit', static fn () => $inspector->inspect($pixels, 'image/png'));
        $exif = $this->exifJpeg($jpeg);
        $gif = 'GIF89a';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';
        $animatedPng = $this->animatedPng($png);
        $animatedWebp = $this->animatedWebp($webp);
        $this->assertCode('question_image_exif', static fn () => $inspector->inspect($exif, 'image/jpeg'));
        $this->assertCode('question_image_gif', static fn () => $inspector->inspect($gif, 'image/gif'));
        $this->assertCode('question_image_svg', static fn () => $inspector->inspect($svg, 'image/svg+xml'));
        $this->assertCode('question_image_animation', static fn () => $inspector->inspect($animatedPng, 'image/png'));
        $this->assertCode('question_image_animation', static fn () => $inspector->inspect($animatedWebp, 'image/webp'));
    }

    public function testStudentPlainTextOmitsTheAssetId(): void
    {
        $id = '018f0000-0000-7000-8000-000000000001';
        $lines = QuestionPlainText::lines([
            'schemaVersion' => 1,
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'Kare'],
                ['type' => 'image_reference', 'mediaId' => $id],
            ],
        ]);
        self::assertSame(['Kare'], $lines);
        self::assertStringNotContainsString($id, implode(' ', $lines));
    }

    public function testBunnyClientPutsChecksumWithoutFollowingRedirects(): void
    {
        $client = new RecordingHttpClient(201);
        $store = $this->bunny($client);
        $key = 'media/platform/image/018f0000-0000-7000-8000-000000000001/0123456789abcdef';
        $bytes = 'png-bytes';
        $store->write($key, $bytes, 'image/png', hash('sha256', $bytes));
        self::assertCount(1, $client->calls);
        self::assertSame('PUT', $client->calls[0]['method']);
        self::assertStringContainsString('/test-zone/'.$key, $client->calls[0]['url']);
        self::assertStringNotContainsString('/pdf/', $client->calls[0]['url']);
        $headers = $client->calls[0]['options']['headers'];
        self::assertSame('test-access-key', $headers['AccessKey']);
        self::assertSame(strtoupper(hash('sha256', $bytes)), $headers['Checksum']);
        self::assertSame(0, $client->calls[0]['options']['max_redirects']);

        $redirect = new RecordingHttpClient(302);
        try {
            $this->bunny($redirect)->write($key, $bytes, 'image/png', hash('sha256', $bytes));
            self::fail('Redirect response was accepted.');
        } catch (QuestionException $exception) {
            self::assertSame('question_image_store', $exception->getMessage());
        }
        self::assertCount(1, $redirect->calls);
    }

    public function testFailedRegistrationCleansTheObjectOnceAndKeepsTheOriginalError(): void
    {
        $store = new MemoryQuestionImageStore();
        $registrar = new QuestionImageRegistrar(
            new QuestionImageInspector(),
            new QuestionImageStoreResolver($store, $this->bunny(new RecordingHttpClient(201)), new BunnyStorageConfiguration('test-zone', 'test-access-key', BunnyStorageConfiguration::ENDPOINT), 'local'),
            new StorageKeyFactory(),
            new ThrowingQuestionImageMetadata(),
            new AllowQuestionImageUpload(),
        );
        $user = User::create('image@example.com', 'image@example.com', 'Image', 'User', 'hash', UserRole::Teacher);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        $user->addGlobalRole(UserRole::SuperAdmin);
        try {
            $registrar->receive($user, QuestionScope::Platform, null, $this->png(2, 2), 'image/png');
        } catch (\RuntimeException $exception) {
            self::assertSame('db-failed', $exception->getMessage());
            self::assertSame(1, $store->deletes);
            self::assertSame(1, $store->writes);
            self::assertStringNotContainsString('media/', $exception->getMessage());

            return;
        }
        self::fail('Registration failure was hidden.');
    }

    private function bunny(RecordingHttpClient $client): BunnyQuestionImageClient
    {
        return new BunnyQuestionImageClient(
            $client,
            new BunnyStorageConfiguration('test-zone', 'test-access-key', BunnyStorageConfiguration::ENDPOINT),
            new class extends AbstractLogger {
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                }
            },
        );
    }

    private function assertCode(string $code, \Closure $call): void
    {
        try {
            $call();
            self::fail('Image was accepted.');
        } catch (QuestionException $exception) {
            self::assertSame($code, $exception->getMessage());
        }
    }

    private function png(int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Image fixture needs a positive size.');
        }
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        ob_start();
        imagepng($image);
        imagedestroy($image);
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        return $bytes;
    }

    private function jpeg(int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Image fixture needs a positive size.');
        }
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        ob_start();
        imagejpeg($image);
        imagedestroy($image);
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        return $bytes;
    }

    private function webp(int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Image fixture needs a positive size.');
        }
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        ob_start();
        imagewebp($image);
        imagedestroy($image);
        $bytes = ob_get_clean();
        self::assertIsString($bytes);

        return $bytes;
    }

    private function exifJpeg(string $jpeg): string
    {
        $segment = "\xFF\xE1".pack('n', 10)."Exif\0\0\0\0";

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    private function animatedPng(string $png): string
    {
        $chunk = pack('N', 8).'acTL'.pack('NN', 2, 0);
        $chunk .= pack('N', crc32(substr($chunk, 4)));

        return substr($png, 0, 8).$chunk.substr($png, 8);
    }

    private function animatedWebp(string $webp): string
    {
        $chunk = 'ANIM'.pack('V', 6)."\0\0\0\0\0\0";

        return substr($webp, 0, 12).$chunk.substr($webp, 12);
    }
}

final class RecordingHttpClient implements \Symfony\Contracts\HttpClient\HttpClientInterface
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(private readonly int $status)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new RecordingResponse($this->status);
    }

    public function stream(iterable|\Symfony\Contracts\HttpClient\ResponseInterface $responses, ?float $timeout = null): \Symfony\Contracts\HttpClient\ResponseStreamInterface
    {
        throw new \BadMethodCallException();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return $this;
    }
}

final class RecordingResponse implements \Symfony\Contracts\HttpClient\ResponseInterface
{
    public function __construct(private readonly int $status)
    {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    public function getContent(bool $throw = true): string
    {
        return '';
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

final class MemoryQuestionImageStore implements QuestionImageByteStore
{
    public int $writes = 0;
    public int $deletes = 0;

    public function write(string $storageKey, string $bytes, string $mimeType, string $sha256): void
    {
        ++$this->writes;
    }

    public function deleteQuietly(string $storageKey): void
    {
        ++$this->deletes;
    }
}

final class AllowQuestionImageUpload extends QuestionImageAuthorizer
{
    public function __construct()
    {
    }

    public function assertMayUpload(User $actor, QuestionScope $scope, ?\App\Entity\Institution $institution): void
    {
    }
}

final class ThrowingQuestionImageMetadata implements QuestionImageMetadataPort
{
    public function register(
        User $actor,
        \App\Enum\StoredMediaAssetScope $scope,
        ?\App\Entity\Institution $institution,
        StoredMediaStorageProvider $provider,
        string $filename,
        string $mimeType,
        int $byteSize,
        string $sha256,
        Uuid $assetId,
    ): \App\Entity\StoredMediaAsset {
        throw new \RuntimeException('db-failed');
    }
}

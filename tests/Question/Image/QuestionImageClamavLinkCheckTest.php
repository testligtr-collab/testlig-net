<?php

declare(strict_types=1);

namespace App\Tests\Question\Image;

use App\Enum\StoredMediaAssetKind;
use App\Enum\StoredMediaAssetScope;
use App\Enum\StoredMediaAssetStatus;
use App\Enum\StoredMediaScanStatus;
use App\Enum\StoredMediaStorageProvider;
use App\Question\Image\QuestionImageStatusLabel;
use App\Question\Image\Scan\QuestionImageClamavLinkCheck;
use App\Question\Image\Scan\QuestionImageClamavLinkTransport;
use App\Question\Image\Scan\QuestionImageScanSnapshot;
use App\Security\WebRuntimeIdentityProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class QuestionImageClamavLinkCheckTest extends TestCase
{
    public function testIdentityMismatchDoesNotOpenTheSocket(): void
    {
        $transport = new RecordingLinkTransport();
        $check = new QuestionImageClamavLinkCheck(new FixedIdentityProbe([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [5002],
        ], 'fpm-fcgi'), $transport, '985');

        $result = $check->run();

        self::assertSame('no', $result['identity']);
        self::assertSame('not_attempted', $result['connection']);
        self::assertSame(0, $transport->calls);
        self::assertSame('Kimlik eşleşmedi. Bağlantı denenmedi.', $check->message($result));
    }

    public function testMatchingIdentitySendsOnlyPingAndVersion(): void
    {
        $transport = new RecordingLinkTransport("PONG\n", "ClamAV 1.4.3/28158/Thu Oct 16 08:22:00 2025\n");
        $check = new QuestionImageClamavLinkCheck(new FixedIdentityProbe([
            'euid' => 5002,
            'egid' => 5002,
            'groups' => [985, 5002],
        ], 'fpm-fcgi'), $transport, '985');

        $result = $check->run();

        self::assertSame(['ping', 'version'], $transport->commands);
        self::assertSame('yes', $result['identity']);
        self::assertSame('yes', $result['connection']);
        self::assertSame('yes', $result['version']);
        self::assertStringNotContainsString('28158', $check->message($result));
    }

    public function testLabelsFollowTheStoredAsset(): void
    {
        self::assertSame('Tarama bekliyor', QuestionImageStatusLabel::fromSnapshot($this->snapshot(StoredMediaAssetStatus::Pending, StoredMediaScanStatus::Pending)));
        self::assertSame('Kullanıma hazır', QuestionImageStatusLabel::fromSnapshot($this->snapshot(StoredMediaAssetStatus::Ready, StoredMediaScanStatus::Clean)));
        self::assertSame('Karantinada', QuestionImageStatusLabel::fromSnapshot($this->snapshot(StoredMediaAssetStatus::Quarantined, StoredMediaScanStatus::Infected)));
        self::assertSame('Tarama başarısız', QuestionImageStatusLabel::fromSnapshot($this->snapshot(StoredMediaAssetStatus::Pending, StoredMediaScanStatus::Failed)));
        self::assertSame('Arşiv', QuestionImageStatusLabel::fromSnapshot($this->snapshot(StoredMediaAssetStatus::Archived, StoredMediaScanStatus::Clean)));
    }

    private function snapshot(StoredMediaAssetStatus $status, StoredMediaScanStatus $scan): QuestionImageScanSnapshot
    {
        return new QuestionImageScanSnapshot(
            Uuid::v7(),
            StoredMediaAssetScope::Platform,
            null,
            StoredMediaAssetKind::Image,
            $status,
            $scan,
            StoredMediaStorageProvider::Local,
            'hidden-key',
            12,
            str_repeat('a', 64),
        );
    }
}

final class FixedIdentityProbe implements WebRuntimeIdentityProbe
{
    /**
     * @param array{euid: int, egid: int, groups: list<int>} $credentials
     */
    public function __construct(
        private readonly array $credentials,
        private readonly string $sapiName,
    ) {
    }

    public function sapi(): string
    {
        return $this->sapiName;
    }

    public function available(): bool
    {
        return true;
    }

    public function credentials(): mixed
    {
        return $this->credentials;
    }
}

final class RecordingLinkTransport implements QuestionImageClamavLinkTransport
{
    public int $calls = 0;

    /** @var list<string> */
    public array $commands = [];

    public function __construct(
        private readonly string $pong = '',
        private readonly string $version = '',
    ) {
    }

    public function ping(): string
    {
        ++$this->calls;
        $this->commands[] = 'ping';

        return $this->pong;
    }

    public function version(): string
    {
        ++$this->calls;
        $this->commands[] = 'version';

        return $this->version;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Question\Image;

use App\Question\Image\Scan\ClamavDnsTxtLookup;
use App\Question\Image\Scan\ClamavLocalDatabaseVersions;
use App\Question\Image\Scan\ClamavPublishedDatabaseVersion;
use App\Question\Image\Scan\ClamavPublishedTxtSource;
use App\Question\Image\Scan\ClamdLoadedDailyVersion;
use App\Question\Image\Scan\ConfiguredClamavSignatureObservation;
use App\Question\Image\Scan\CvdDatabaseDirectoryReader;
use App\Question\Image\Scan\CvdHeaderVersion;
use PHPUnit\Framework\TestCase;

final class ClamavFreshnessTest extends TestCase
{
    public function testLongerIntegerDoesNotMatchAndWrongFieldIsRejected(): void
    {
        $shorter = ClamavPublishedDatabaseVersion::published([[
            'txt' => '1.0.9:123:1:1:0:1:1:1',
            'entries' => ['1.0.9:123:1:1:0:1:1:1'],
        ]]);
        self::assertSame(['main' => 123, 'daily' => 1, 'bytecode' => 1], $shorter['versions']);
        $longer = ClamavPublishedDatabaseVersion::parse('1.0.9:1234:1:1:0:1:1:1');
        self::assertIsArray($longer);
        self::assertNotSame($shorter['versions'], $longer);
        $wrongField = ClamavPublishedDatabaseVersion::parse('1.0.9:59:123:1:0:63:1:7');
        self::assertIsArray($wrongField);
        self::assertSame(59, $wrongField['main']);
        self::assertSame(123, $wrongField['daily']);
        self::assertNotSame(123, $wrongField['main']);
    }

    public function testContradictoryAndMalformedTxtStop(): void
    {
        $conflict = ClamavPublishedDatabaseVersion::published([
            ['txt' => '0.103.1:59:26104:1615408140:0:63:49191:333', 'entries' => ['0.103.1:59:26104:1615408140:0:63:49191:333']],
            ['txt' => '1.0.9:60:26104:1615408140:0:63:49191:333', 'entries' => ['1.0.9:60:26104:1615408140:0:63:49191:333']],
        ]);
        self::assertSame('mismatch', $conflict['status']);
        self::assertNull(ClamavPublishedDatabaseVersion::parse('59:26104:333'));
        self::assertSame('unavailable', ClamavPublishedDatabaseVersion::published([])['status']);
    }

    public function testEmptyDatabaseDirectoryDoesNotOpenAFile(): void
    {
        self::assertNull((new CvdDatabaseDirectoryReader(''))->read());
        self::assertNull((new CvdDatabaseDirectoryReader('relative/clamav'))->read());
    }

    public function testDisagreeingLocalFilesAndHeaderVersion(): void
    {
        $root = sys_get_temp_dir().'/testlig-cvd-'.bin2hex(random_bytes(4));
        mkdir($root);
        try {
            file_put_contents($root.'/daily.cvd', $this->header(123));
            file_put_contents($root.'/daily.cld', $this->header(1234));
            file_put_contents($root.'/main.cvd', $this->header(59));
            file_put_contents($root.'/bytecode.cvd', $this->header(333));
            $directory = str_replace('\\', '/', $root);
            if (1 === preg_match('#^/(?:[A-Za-z0-9._-]{1,64}/){0,16}[A-Za-z0-9._-]{1,64}$#', $directory)) {
                self::assertNull((new CvdDatabaseDirectoryReader($directory))->read());
            }
            self::assertSame(123, CvdHeaderVersion::parse($this->header(123)));
            self::assertNull(CvdHeaderVersion::parse('not-a-cvd'));
        } finally {
            foreach (['daily.cvd', 'daily.cld', 'main.cvd', 'bytecode.cvd'] as $name) {
                $path = $root.'/'.$name;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($root);
        }
    }

    public function testObservationRejectsDnsLocalAndLoadedDailyMismatches(): void
    {
        $files = new ArrayDatabaseVersions(['main' => 59, 'daily' => 26104, 'bytecode' => 333]);
        $txt = '0.103.1:59:26104:1615408140:0:63:49191:333';
        $match = new ConfiguredClamavSignatureObservation(
            $files,
            new ArrayTxtSource([['txt' => $txt, 'entries' => [$txt]]]),
            new ClamdLoadedDailyVersion(new VersionTransport("ClamAV 1.4.6/26104/Thu Oct 15 12:00:00 2026\n")),
        );
        $snapshot = $match->capture();
        self::assertNotNull($snapshot);
        self::assertSame(26104, $snapshot->loadedDaily);

        $wrongDaily = new ConfiguredClamavSignatureObservation(
            $files,
            new ArrayTxtSource([['txt' => '0.103.1:59:1234:1615408140:0:63:49191:333', 'entries' => ['0.103.1:59:1234:1615408140:0:63:49191:333']]]),
            new ClamdLoadedDailyVersion(new VersionTransport("ClamAV 1.4.6/26104/Thu Oct 15 12:00:00 2026\n")),
        );
        self::assertNull($wrongDaily->capture());

        $wrongField = new ConfiguredClamavSignatureObservation(
            $files,
            new ArrayTxtSource([['txt' => '0.103.1:26104:59:1615408140:0:63:49191:333', 'entries' => ['0.103.1:26104:59:1615408140:0:63:49191:333']]]),
            new ClamdLoadedDailyVersion(new VersionTransport("ClamAV 1.4.6/59/Thu Oct 15 12:00:00 2026\n")),
        );
        self::assertNull($wrongField->capture());

        $loaded = new ConfiguredClamavSignatureObservation(
            $files,
            new ArrayTxtSource([['txt' => $txt, 'entries' => [$txt]]]),
            new ClamdLoadedDailyVersion(new VersionTransport("ClamAV 1.4.6/1234/Thu Oct 15 12:00:00 2026\n")),
        );
        self::assertNull($loaded->capture());
        self::assertNull((new ClamdLoadedDailyVersion(new VersionTransport("ClamAV 1.4.6\n")))->read());
    }

    public function testDnsTxtPacketAndTimeout(): void
    {
        $text = '0.103.1:59:26104:1615408140:0:63:49191:333';
        $lookup = new ClamavDnsTxtLookup('127.0.0.1', 9, 0.2);
        $parse = \Closure::bind(function (string $packet, int $id): array {
            return $this->txtRecords($packet, $id);
        }, $lookup, ClamavDnsTxtLookup::class);
        self::assertInstanceOf(\Closure::class, $parse);
        $records = $parse($this->txtPacket(7, $text), 7);
        self::assertSame([['txt' => $text, 'entries' => [$text]]], $records);
        self::assertSame([], $parse($this->txtPacket(7, str_repeat('a', 513)), 7));
        self::assertSame([], (new ClamavDnsTxtLookup('', 53, 0.2))->records());
        $started = microtime(true);
        self::assertSame([], (new ClamavDnsTxtLookup('127.0.0.1', 9, 0.2))->records());
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    private function header(int $version): string
    {
        $line = 'ClamAV-VDB:15 Oct 2026 12-00:'.$version.':10:63:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa:bbbb:clamav:1';

        return str_pad(substr($line, 0, 511)."\0", 512, "\0");
    }

    private function txtPacket(int $id, string $text): string
    {
        $name = '';
        foreach (explode('.', ClamavDnsTxtLookup::HOST) as $label) {
            $name .= \chr(\strlen($label)).$label;
        }
        $name .= "\0";
        $question = $name.pack('nn', 16, 1);
        $data = \chr(\strlen($text)).$text;
        $answer = pack('n', 0xC00C).pack('nnNn', 16, 1, 30, \strlen($data)).$data;

        return pack('nnnnnn', $id, 0x8180, 1, 1, 0, 0).$question.$answer;
    }
}

final class ArrayDatabaseVersions implements ClamavLocalDatabaseVersions
{
    /**
     * @param ?array{main: int, daily: int, bytecode: int} $versions
     */
    public function __construct(private readonly ?array $versions)
    {
    }

    public function read(): ?array
    {
        return $this->versions;
    }
}

final class ArrayTxtSource implements ClamavPublishedTxtSource
{
    /**
     * @param list<array{txt: string, entries: list<string>}> $records
     */
    public function __construct(private readonly array $records)
    {
    }

    public function records(): array
    {
        return $this->records;
    }
}

final class VersionTransport implements \App\Question\Image\Scan\ClamdInstreamTransport
{
    public function __construct(private readonly string $response)
    {
    }

    public function exchange(iterable $framed, float $timeoutSeconds, int $maxResponseBytes): string
    {
        return $this->response;
    }
}

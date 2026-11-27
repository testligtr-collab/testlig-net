<?php

declare(strict_types=1);

namespace App\Tests\Release;

use App\Release\ReleaseRevision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseRevisionTest extends TestCase
{
    private const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/testlig-revision-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testFullLowercaseShaWithTrailingNewlineIsAccepted(): void
    {
        self::assertSame(self::SHA, ReleaseRevision::parse(self::SHA));
        self::assertSame(self::SHA, ReleaseRevision::parse(self::SHA."\n"));
        self::assertSame(self::SHA, ReleaseRevision::parse(self::SHA."\r\n"));
    }

    #[DataProvider('rejected')]
    public function testAnythingElseIsRejectedAndNeverRepaired(string $content): void
    {
        self::assertNull(ReleaseRevision::parse($content));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => ['a1b2c3d'];
        yield 'uppercase' => [strtoupper('a1b2c3d4e5f60718293a4b5c6d7e8f9012345678')];
        yield 'with prefix' => ['sha=a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'];
        yield 'with space' => [' a1b2c3d4e5f60718293a4b5c6d7e8f9012345678'];
        yield 'two lines' => ["a1b2c3d4e5f60718293a4b5c6d7e8f9012345678\nbeef"];
        yield 'too long' => ['a1b2c3d4e5f60718293a4b5c6d7e8f9012345678ab'];
        yield 'not hex' => ['g1b2c3d4e5f60718293a4b5c6d7e8f9012345678'];
        yield 'placeholder' => ['unknown'];
    }

    public function testFileIsReadOnlyWhenPresentAndValid(): void
    {
        file_put_contents($this->dir.'/REVISION', self::SHA."\n");
        self::assertSame(self::SHA, ReleaseRevision::fromFile($this->dir.'/REVISION'));

        file_put_contents($this->dir.'/BAD', "broken\n");
        self::assertNull(ReleaseRevision::fromFile($this->dir.'/BAD'));
        self::assertNull(ReleaseRevision::fromFile($this->dir.'/MISSING'));
    }
}

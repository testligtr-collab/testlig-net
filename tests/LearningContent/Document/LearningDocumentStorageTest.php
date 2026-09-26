<?php

declare(strict_types=1);

namespace App\Tests\LearningContent\Document;

use App\Exception\LearningContentException;
use App\LearningContent\Document\LearningDocumentStorage;
use PHPUnit\Framework\TestCase;

final class LearningDocumentStorageTest extends TestCase
{
    public function testWriteAndReadRoundTripOutsideGivenDirectory(): void
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'learning-doc-'.bin2hex(random_bytes(4));
        $storage = new LearningDocumentStorage($dir);
        $bytes = "%PDF-1.4\nround\n";
        $key = $storage->write($bytes);

        self::assertSame(32, \strlen($key));
        self::assertSame(1, preg_match('/^[a-f0-9]{32}$/', $key));
        self::assertSame($bytes, $storage->read($key, hash('sha256', $bytes)));
        self::assertStringStartsWith($dir, $storage->absolutePath($key));

        try {
            $storage->read($key, str_repeat('a', 64));
            self::fail();
        } catch (LearningContentException) {
            self::assertFileExists($storage->absolutePath($key));
        }

        $storage->deleteQuietly($key);
        self::assertFileDoesNotExist($storage->absolutePath($key));
        @rmdir($dir);
    }

    public function testRejectsTraversalKey(): void
    {
        $storage = new LearningDocumentStorage(sys_get_temp_dir());
        $this->expectException(LearningContentException::class);
        $storage->read('../secret', str_repeat('a', 64));
    }
}

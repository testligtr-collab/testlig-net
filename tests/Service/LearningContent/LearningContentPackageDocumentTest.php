<?php

declare(strict_types=1);

namespace App\Tests\Service\LearningContent;

use App\Exception\LearningContentPackageException;
use App\LearningContent\Content\LearningContentDocumentValidator;
use App\Service\LearningContent\LearningContentPackageAllowlist;
use App\Service\LearningContent\LearningContentPackageDocument;
use App\Service\LearningContent\LearningContentPackageTarget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class LearningContentPackageDocumentTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = \dirname(__DIR__, 3);
    }

    public function testApprovedFixtureParsesTheNineBlocks(): void
    {
        $loaded = $this->documents()->load($this->projectDir, LearningContentPackageTarget::mat132());
        $blocks = $loaded->document->blocks;

        self::assertCount(9, $blocks);
        self::assertSame(
            ['heading', 'paragraph', 'callout', 'heading', 'list', 'heading', 'paragraph', 'heading', 'callout'],
            array_column($blocks, 'type'),
        );
        self::assertSame('info', $blocks[2]['variant'] ?? null);
        self::assertSame('tip', $blocks[8]['variant'] ?? null);
        self::assertSame('Eş Nesneleri Tanıyalım', $blocks[0]['text'] ?? null);
        self::assertSame('Nasıl Karşılaştırırız?', $blocks[3]['text'] ?? null);
        self::assertSame('Birlikte Düşünelim', $blocks[5]['text'] ?? null);
        self::assertSame('Hatırlayalım', $blocks[7]['text'] ?? null);
        self::assertSame([
            'Renklerine bak.',
            'Biçimlerine bak.',
            'Büyüklüklerine bak.',
            'Yönü değişmiş olsa da bu özellikler aynıysa nesneleri eş olarak değerlendir.',
        ], $blocks[4]['items'] ?? null);
        $raw = $this->rawFixture();
        self::assertSame(
            'Eş nesneleri renk, biçim ve büyüklüklerine göre karşılaştırmayı öğren.',
            $raw['summary'],
        );
        self::assertSame(LearningContentPackageTarget::mat132()->summary, $raw['summary']);
        $checksum = $loaded->fixtureChecksum;
        self::assertSame(hash('sha256', (string) file_get_contents($this->lessonPath())), $checksum);
        self::assertNotSame('cd54683002dddc8d95366bd42cbd847bf7035558ab9f5539cc6527f76e693c7b', $checksum);
        $again = $this->documents()->load($this->projectDir, LearningContentPackageTarget::mat132());
        self::assertSame($checksum, $again->fixtureChecksum);
    }

    public function testUnknownBlockIsRejected(): void
    {
        $raw = $this->rawFixture();
        $document = $raw['document'];
        self::assertIsArray($document);
        $blocks = $document['blocks'];
        self::assertIsArray($blocks);
        $blocks[1] = ['type' => 'widget', 'text' => 'nope'];
        $document['blocks'] = $blocks;
        $raw['document'] = $document;

        $this->expectException(LearningContentPackageException::class);
        $this->loadRaw($raw);
    }

    public function testUnsafeTextIsRejected(): void
    {
        $raw = $this->rawFixture();
        $document = $raw['document'];
        self::assertIsArray($document);
        $blocks = $document['blocks'];
        self::assertIsArray($blocks);
        $blocks[1] = ['type' => 'paragraph', 'text' => '<iframe src="https://example.test"></iframe>'];
        $document['blocks'] = $blocks;
        $raw['document'] = $document;

        $this->expectException(LearningContentPackageException::class);
        $this->loadRaw($raw);
    }

    public function testRawTwigIsRejected(): void
    {
        $raw = $this->rawFixture();
        $document = $raw['document'];
        self::assertIsArray($document);
        $blocks = $document['blocks'];
        self::assertIsArray($blocks);
        $blocks[1] = ['type' => 'paragraph', 'text' => 'Merhaba {{ name }}'];
        $document['blocks'] = $blocks;
        $raw['document'] = $document;

        $this->expectException(LearningContentPackageException::class);
        $this->loadRaw($raw);
    }

    public function testWrongIdentityIsRejected(): void
    {
        $rejected = 0;
        foreach ([
            'subject_code' => 'mat',
            'grade_level' => 2,
            'program_code' => 'other_program',
            'program_version' => 'OTHER-2026',
            'outcome_code' => 'mat_1_3_1',
            'official_code' => 'MAT.1.3.1',
            'summary' => 'Başka özet',
        ] as $key => $value) {
            $raw = $this->rawFixture();
            $raw[$key] = $value;
            try {
                $this->loadRaw($raw);
                self::fail($key);
            } catch (LearningContentPackageException) {
                ++$rejected;
            }
        }

        self::assertSame(7, $rejected);

        $this->expectException(LearningContentPackageException::class);
        (new LearningContentPackageAllowlist())->resolve('data/content/tymm-2026/grade-1/matematik/mat-1-3-1');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawFixture(): array
    {
        $parsed = Yaml::parseFile($this->lessonPath());
        self::assertIsArray($parsed);

        /* @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function loadRaw(array $raw): void
    {
        $root = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'testlig-pkg-'.bin2hex(random_bytes(4));
        $directory = $root.\DIRECTORY_SEPARATOR.str_replace('/', \DIRECTORY_SEPARATOR, LearningContentPackageTarget::mat132()->directory);
        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail('temp directory');
        }
        $encoded = Yaml::dump($raw, 8, 2);
        file_put_contents($directory.\DIRECTORY_SEPARATOR.'lesson.yaml', $encoded);
        try {
            $this->documents()->load($root, LearningContentPackageTarget::mat132());
        } finally {
            @unlink($directory.\DIRECTORY_SEPARATOR.'lesson.yaml');
        }
    }

    private function documents(): LearningContentPackageDocument
    {
        return new LearningContentPackageDocument(new LearningContentDocumentValidator());
    }

    private function lessonPath(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'data'.\DIRECTORY_SEPARATOR.'content'
            .\DIRECTORY_SEPARATOR.'tymm-2026'.\DIRECTORY_SEPARATOR.'grade-1'
            .\DIRECTORY_SEPARATOR.'matematik'.\DIRECTORY_SEPARATOR.'mat-1-3-2'
            .\DIRECTORY_SEPARATOR.'lesson.yaml';
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\LearningContent\Document;

use App\Exception\LearningContentException;
use App\LearningContent\Document\VideoUrlParser;
use PHPUnit\Framework\TestCase;

final class VideoUrlParserTest extends TestCase
{
    private VideoUrlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new VideoUrlParser();
    }

    public function testParsesYouTubeAndVimeoUrls(): void
    {
        $youtube = $this->parser->parse('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        self::assertSame('youtube', $youtube->provider);
        self::assertSame('dQw4w9WgXcQ', $youtube->providerVideoId);
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $youtube->playerUrl());

        $short = $this->parser->parse('https://youtu.be/dQw4w9WgXcQ');
        self::assertSame('dQw4w9WgXcQ', $short->providerVideoId);

        $embed = $this->parser->parse('https://youtube-nocookie.com/embed/dQw4w9WgXcQ');
        self::assertSame('youtube', $embed->provider);

        $vimeo = $this->parser->parse('https://vimeo.com/123456789');
        self::assertSame('vimeo', $vimeo->provider);
        self::assertSame('123456789', $vimeo->providerVideoId);
        self::assertSame('https://player.vimeo.com/video/123456789', $vimeo->playerUrl());

        $player = $this->parser->parse('https://www.vimeo.com/video/123456');
        self::assertSame('123456', $player->providerVideoId);
    }

    public function testRejectsFakeHostsUnknownProvidersAndSsrfUrls(): void
    {
        $rejected = [
            'https://evil.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'https://example.com/watch?v=dQw4w9WgXcQ',
            '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ<',
            'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://user:pass@www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com:443/watch?v=dQw4w9WgXcQ',
            'https://127.0.0.1/watch?v=dQw4w9WgXcQ',
            'https://169.254.169.254/latest/meta-data',
            'file:///etc/passwd',
            'https://www.youtube.com/watch?v=short',
            'https://vimeo.com/12345',
        ];

        foreach ($rejected as $url) {
            try {
                $this->parser->parse($url);
                self::fail($url);
            } catch (LearningContentException $e) {
                self::assertSame(VideoUrlParser::MESSAGE, $e->getMessage());
            }
        }
    }
}

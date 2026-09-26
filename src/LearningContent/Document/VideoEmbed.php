<?php

declare(strict_types=1);

namespace App\LearningContent\Document;

final readonly class VideoEmbed
{
    public function __construct(
        public string $provider,
        public string $providerVideoId,
    ) {
    }

    public function playerUrl(): string
    {
        if ('vimeo' === $this->provider) {
            return 'https://player.vimeo.com/video/'.$this->providerVideoId;
        }

        return 'https://www.youtube-nocookie.com/embed/'.$this->providerVideoId;
    }
}

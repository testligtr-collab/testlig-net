<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A route name, or an already checked internal path. Never an HTTP response.
 */
final readonly class PostLoginDestination
{
    private function __construct(
        public ?string $route,
        public ?string $path,
    ) {
    }

    public static function route(string $route): self
    {
        return new self($route, null);
    }

    public static function path(string $path): self
    {
        return new self(null, $path);
    }

    public function location(UrlGeneratorInterface $urls): string
    {
        if (null !== $this->path) {
            return $this->path;
        }

        return $urls->generate((string) $this->route);
    }
}

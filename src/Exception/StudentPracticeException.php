<?php

declare(strict_types=1);

namespace App\Exception;

final class StudentPracticeException extends \RuntimeException
{
    private function __construct(private readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public static function notFound(): self
    {
        return new self('not_found');
    }

    public static function rejected(string $reason): self
    {
        return new self($reason);
    }
}

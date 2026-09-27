<?php

declare(strict_types=1);

namespace App\Exception;

final class InstitutionTestAssignmentException extends \RuntimeException
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

    public static function forbidden(): self
    {
        return new self('forbidden');
    }

    public static function notAssignable(): self
    {
        return new self('not_assignable');
    }

    public static function emptyClass(): self
    {
        return new self('empty_class');
    }

    public static function overlap(): self
    {
        return new self('overlap');
    }

    public static function window(): self
    {
        return new self('window');
    }
}

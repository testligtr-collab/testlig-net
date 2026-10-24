<?php

declare(strict_types=1);

namespace App\Exception;

final class UserModulePermissionException extends \RuntimeException
{
    private function __construct(
        private readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public static function unauthorized(): self
    {
        return new self('unauthorized', 'Module permission operation is not authorized.');
    }

    public static function invalid(): self
    {
        return new self('invalid', 'Module permission code is not in the catalog.');
    }

    public static function ineligible(): self
    {
        return new self('ineligible', 'This account cannot receive module permissions.');
    }

    public static function conflict(): self
    {
        return new self('conflict', 'Module permission version conflict.');
    }
}

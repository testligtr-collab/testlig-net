<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Process credentials for the PHP runtime that is executing the current request.
 */
interface WebRuntimeIdentityProbe
{
    public function sapi(): string;

    public function available(): bool;

    public function credentials(): mixed;
}

<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Clock\ClockInterface;

/**
 * Fails at the application-link timestamp, after InstitutionCreator has returned.
 */
final class LinkBoundaryClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        throw new LinkBoundaryException('Institution application link boundary.');
    }
}

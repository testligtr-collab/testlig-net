<?php

declare(strict_types=1);

namespace App\Presentation;

final class PlacementPosition
{
    public static function next(?int $highestOccupied): int
    {
        if (null === $highestOccupied || $highestOccupied < 0) {
            return 0;
        }

        return $highestOccupied + 1;
    }
}

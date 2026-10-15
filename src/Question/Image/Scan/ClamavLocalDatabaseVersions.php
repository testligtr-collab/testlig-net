<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

interface ClamavLocalDatabaseVersions
{
    /**
     * @return ?array{main: int, daily: int, bytecode: int}
     */
    public function read(): ?array;
}

<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Published grade that has at least one published catalog subject.
 */
final readonly class PublicCatalogGradeCard
{
    public function __construct(
        public int $grade,
        public int $subjectCount,
        public string $tone,
    ) {
    }

    public function label(): string
    {
        return $this->grade.'. sınıf';
    }
}

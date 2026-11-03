<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class HomeworkClassroomOption
{
    public function __construct(
        public string $id,
        public string $name,
        public string $institutionName,
    ) {
    }

    public function label(bool $withInstitution): string
    {
        return $withInstitution ? $this->name.' — '.$this->institutionName : $this->name;
    }
}

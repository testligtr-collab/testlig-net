<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\InstitutionBranchKind;
use App\Enum\InstitutionBranchStatus;
use App\Enum\InstitutionStatus;

final class AdminBranchListItemView
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $code,
        public readonly InstitutionBranchKind $kind,
        public readonly InstitutionBranchStatus $status,
        public readonly string $institutionId,
        public readonly string $institutionName,
        public readonly InstitutionStatus $institutionStatus,
        public readonly string $accessNote,
        public readonly bool $accessOpen,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\InstitutionBranchStatus;
use App\Enum\InstitutionStatus;

/**
 * Local branch status and effective access stay separate.
 * A branch never grants membership or bypasses a closed institution.
 */
final class InstitutionBranchAccess
{
    public static function isOpen(InstitutionBranchStatus $local, InstitutionStatus $institution): bool
    {
        return InstitutionBranchStatus::Active === $local && InstitutionStatus::Active === $institution;
    }

    public static function note(InstitutionBranchStatus $local, InstitutionStatus $institution): string
    {
        if (self::isOpen($local, $institution)) {
            return 'Erişim açık';
        }
        if (InstitutionStatus::Active !== $institution) {
            return 'Erişim kapalı. Bağlı kurum aktif değil.';
        }

        return 'Erişim kapalı. Şube kaydı aktif değil.';
    }
}

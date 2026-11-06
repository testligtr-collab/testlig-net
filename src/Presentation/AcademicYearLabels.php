<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Enum\AcademicYearStatus;

final class AcademicYearLabels
{
    public static function status(AcademicYearStatus $status): string
    {
        return match ($status) {
            AcademicYearStatus::Active => 'Aktif',
            AcademicYearStatus::Planned => 'Planlandı',
            AcademicYearStatus::Closed => 'Kapandı',
        };
    }

    public static function range(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): string
    {
        return self::day($startsAt).' – '.self::day($endsAt);
    }

    private static function day(\DateTimeImmutable $date): string
    {
        $months = [
            1 => 'Oca',
            2 => 'Şub',
            3 => 'Mar',
            4 => 'Nis',
            5 => 'May',
            6 => 'Haz',
            7 => 'Tem',
            8 => 'Ağu',
            9 => 'Eyl',
            10 => 'Eki',
            11 => 'Kas',
            12 => 'Ara',
        ];

        return $date->format('d').' '.$months[(int) $date->format('n')].' '.$date->format('Y');
    }
}

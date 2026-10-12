<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Teacher professional-profile failures. Messages must not leak other users' data.
 */
final class TeacherProfessionalProfileException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function unauthorized(): self
    {
        return new self('Bu işlem için yetkiniz yok.');
    }

    public static function catalogClosed(): self
    {
        return new self('Seçilebilecek aktif branş yok. Profil kaydı kapalı.');
    }

    public static function invalidSubject(): self
    {
        return new self('Geçerli bir aktif branş seçin.');
    }

    public static function invalidGrades(): self
    {
        return new self('En az bir geçerli sınıf seçin.');
    }

    public static function invalidLocation(): self
    {
        return new self('Geçerli bir il, ilçe ve okul seçin.');
    }

    public static function conflict(): self
    {
        return new self('Profil kaydedilemedi. Yazım yapılmadı.');
    }
}

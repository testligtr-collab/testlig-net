<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Public /kayit UX paths. Server maps these to roles; clients cannot pick privileges.
 *
 * Teacher/institution paths create a base ROLE_USER account only; pending applications
 * are submitted later after e-posta doğrulama (managers enforce active+verified).
 */
enum RegistrationFlow: string
{
    case Student = 'ogrenci';
    case Parent = 'veli';
    case TeacherApplication = 'ogretmen';
    case InstitutionApplication = 'kurum';

    public function initialGlobalRole(): UserRole
    {
        return match ($this) {
            self::Student => UserRole::Student,
            self::Parent => UserRole::Parent,
            self::TeacherApplication, self::InstitutionApplication => UserRole::User,
        };
    }

    public function accountType(): ?AccountType
    {
        return match ($this) {
            self::Student => AccountType::Student,
            self::Parent => AccountType::Parent,
            self::TeacherApplication, self::InstitutionApplication => null,
        };
    }

    public function requiresApplicationFollowUp(): bool
    {
        return match ($this) {
            self::TeacherApplication, self::InstitutionApplication => true,
            self::Student, self::Parent => false,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Student => 'Öğrenci',
            self::Parent => 'Veli',
            self::TeacherApplication => 'Öğretmen başvurusu',
            self::InstitutionApplication => 'Kurum başvurusu',
        };
    }

    public function summary(): string
    {
        return match ($this) {
            self::Student => 'Dersler, testler ve gelişim takibi için öğrenci hesabı.',
            self::Parent => 'Çocuğunuzun öğrenme özetini takip etmek için veli hesabı.',
            self::TeacherApplication => 'Öğretmen erişimi için hesap oluşturup inceleme başvurusu gönderin.',
            self::InstitutionApplication => 'Kurum yetkilisi olarak hesap oluşturup inceleme başvurusu gönderin.',
        };
    }

    /**
     * @return list<self>
     */
    public static function publicChoices(): array
    {
        return [
            self::Student,
            self::Parent,
            self::TeacherApplication,
            self::InstitutionApplication,
        ];
    }
}

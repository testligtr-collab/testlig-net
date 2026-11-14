<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\TeacherAssignmentRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\AcademicYearManager;
use App\Service\ClassroomManager;
use App\Service\ClassroomTeacherAssignmentManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionMembershipManager;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;

/**
 * Dedicated account-summary fixtures for GET /hesabim browser shots.
 *
 * Uses a separate institution so shared browser classroom counts stay unchanged.
 */
final class BrowserAccountSummarySeeder
{
    public const LONG_EMAIL = 'browser-account-long-email-wrap-xxxxxxxxxxxxxxxxxxxx@example.test';
    public const LONG_INSTITUTION = 'Uzun Kurum Adi Hesap Ozeti Kartinda Sarilan Ornek Okulu';
    public const LONG_CLASSROOM = 'Uzun Sinif Adi Hesap Ozeti Uyelik Satirinda Sarilir';
    private const PASSWORD = 'Guclu-Parola-123!';

    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly UserRepository $users,
        private readonly InstitutionCreator $institutions,
        private readonly InstitutionStatusManager $institutionStatus,
        private readonly InstitutionMembershipManager $memberships,
        private readonly AcademicYearManager $years,
        private readonly ClassroomManager $classrooms,
        private readonly ClassroomTeacherAssignmentManager $teacherAssignments,
    ) {
    }

    /**
     * @return array{
     *     path: string,
     *     longEmail: string,
     *     longInstitution: string,
     *     longClassroom: string,
     *     longUser: string
     * }
     */
    public function seed(User $superAdmin): array
    {
        $owner = $this->account('browser-account-owner@example.test', 'Hesap', 'Sahibi', UserRole::Teacher);
        $longTeacher = $this->account(
            self::LONG_EMAIL,
            'UzunAdHesapOzetiSarim',
            'UzunSoyadHesapOzetiSarim',
            UserRole::Teacher,
        );

        $institution = $this->institutions->create(
            $superAdmin,
            $owner,
            self::LONG_INSTITUTION,
            InstitutionType::School,
            'browser_account_summary',
        );
        $this->institutionStatus->activate($institution, $superAdmin, 'browser_account_summary');
        $year = $this->years->createPlanned(
            $institution,
            $owner,
            '2098-2099',
            new \DateTimeImmutable('2098-09-01'),
            new \DateTimeImmutable('2099-06-15'),
            'browser_account_summary',
        );
        $this->years->activate($year, $owner, 'browser_account_summary');
        $classroom = $this->classrooms->create(
            $year,
            $owner,
            self::LONG_CLASSROOM,
            GradeLevel::Grade1,
            'browser_account_summary',
            'H',
            24,
        );
        $membership = $this->memberships->addMember(
            $institution,
            $owner,
            $longTeacher,
            InstitutionMembershipRole::Teacher,
            'browser_account_summary',
        );
        $this->teacherAssignments->assign(
            $classroom,
            $owner,
            $membership,
            TeacherAssignmentRole::HomeroomTeacher,
            'browser_account_summary',
        );

        return [
            'path' => '/hesabim',
            'longEmail' => self::LONG_EMAIL,
            'longInstitution' => self::LONG_INSTITUTION,
            'longClassroom' => self::LONG_CLASSROOM,
            'longUser' => self::LONG_EMAIL,
        ];
    }

    private function account(string $email, string $firstName, string $lastName, UserRole $role): User
    {
        $user = $this->userFactory->createAndPersist($email, self::PASSWORD, $firstName, $lastName, $role);
        $user->markEmailVerified(new \DateTimeImmutable('2026-09-28T00:00:00+00:00'));
        $user->transitionTo(UserStatus::Active);
        $this->users->save($user);

        return $user;
    }
}

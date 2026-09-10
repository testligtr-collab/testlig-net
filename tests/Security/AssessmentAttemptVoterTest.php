<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AssessmentAttempt;
use App\Entity\AssessmentDelivery;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Security\AssessmentAttemptPermission;
use App\Tests\Support\AssessmentAttemptTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

final class AssessmentAttemptVoterTest extends KernelTestCase
{
    use AssessmentAttemptTestFixtures;

    private AccessDecisionManagerInterface $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $access = static::getContainer()->get(AccessDecisionManagerInterface::class);
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $access);
        $this->access = $access;
        $this->cleanupDeliveryFixtures();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->cleanupDeliveryFixtures();
        }
        parent::tearDown();
    }

    public function testStudentOwnerTeacherStaffAndCrossTenantMatrix(): void
    {
        $fx = $this->activatedClassroomDelivery('aav1');
        $staffUser = $this->activeUser('aav1-staff@example.com');
        $this->membershipManager()->addMember(
            $fx['institution'],
            $fx['owner'],
            $staffUser,
            InstitutionMembershipRole::Staff,
            'add_staff',
        );

        $otherOwner = $this->activeUser('aav1-xowner@example.com');
        $otherInst = $this->institutionCreator()->create(
            $fx['sa'],
            $otherOwner,
            'aav1 Other School',
            InstitutionType::School,
            'platform_setup',
        );
        self::assertInstanceOf(Institution::class, $otherInst);
        $this->institutionStatus()->activate($otherInst, $fx['sa'], 'activate');
        $crossStudent = $this->activeUser('aav1-xstu@example.com');
        $this->membershipManager()->addMember(
            $otherInst,
            $otherOwner,
            $crossStudent,
            InstitutionMembershipRole::Student,
            'add_xstu',
        );

        $student = $this->reloadUser($fx['student']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $attempt = $this->attempts()->startAttempt($delivery, $student, 'start_vot');
        $attempt = $this->reloadAttempt($attempt->getId());
        $delivery = $this->reloadDelivery($delivery->getId());

        self::assertTrue($this->decide($student, AssessmentAttemptPermission::START, $delivery));
        self::assertTrue($this->decide($student, AssessmentAttemptPermission::VIEW, $attempt));
        self::assertTrue($this->decide($student, AssessmentAttemptPermission::SAVE_ANSWER, $attempt));
        self::assertTrue($this->decide($student, AssessmentAttemptPermission::SUBMIT, $attempt));
        self::assertFalse($this->decide($student, AssessmentAttemptPermission::CANCEL, $attempt));

        $owner = $this->reloadUser($fx['owner']->getId());
        self::assertTrue($this->decide($owner, AssessmentAttemptPermission::VIEW, $attempt));
        self::assertTrue($this->decide($owner, AssessmentAttemptPermission::CANCEL, $attempt));
        self::assertFalse($this->decide($owner, AssessmentAttemptPermission::SAVE_ANSWER, $attempt));
        self::assertFalse($this->decide($owner, AssessmentAttemptPermission::SUBMIT, $attempt));

        $teacher = $this->reloadUser($fx['teacher']->getId());
        self::assertTrue($this->decide($teacher, AssessmentAttemptPermission::VIEW, $attempt));
        self::assertFalse($this->decide($teacher, AssessmentAttemptPermission::CANCEL, $attempt));
        self::assertFalse($this->decide($teacher, AssessmentAttemptPermission::SAVE_ANSWER, $attempt));

        self::assertFalse($this->decide($staffUser, AssessmentAttemptPermission::VIEW, $attempt));
        self::assertFalse($this->decide($staffUser, AssessmentAttemptPermission::CANCEL, $attempt));

        self::assertFalse($this->decide($crossStudent, AssessmentAttemptPermission::START, $delivery));
        self::assertFalse($this->decide($crossStudent, AssessmentAttemptPermission::VIEW, $attempt));

        $admin = $this->activeUser('aav1-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        self::assertFalse($this->decide($admin, AssessmentAttemptPermission::VIEW, $attempt));
    }

    private function decide(User $user, string $attribute, AssessmentAttempt|AssessmentDelivery $subject): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $subject);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AssessmentDelivery;
use App\Entity\User;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\InstitutionMembershipRole;
use App\Enum\UserRole;
use App\Security\AssessmentDeliveryPermission;
use App\Tests\Support\AssessmentDeliveryTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

final class AssessmentDeliveryVoterTest extends KernelTestCase
{
    use AssessmentDeliveryTestFixtures;

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

    public function testOwnerManagerTeacherStaffStudentMatrix(): void
    {
        $ctx = $this->publishedDeliveryContext('advot');
        [$opens, $closes] = $this->defaultWindow();

        $managerUser = $this->activeUser('advot-mgr@example.com');
        $this->membershipManager()->addMember(
            $ctx['institution'],
            $ctx['owner'],
            $managerUser,
            InstitutionMembershipRole::Manager,
            'add_mgr',
        );
        $staffUser = $this->activeUser('advot-staff@example.com');
        $this->membershipManager()->addMember(
            $ctx['institution'],
            $ctx['owner'],
            $staffUser,
            InstitutionMembershipRole::Staff,
            'add_staff',
        );
        $otherTeacher = $this->activeUser('advot-ot@example.com');
        $otherTeacherMembership = $this->membershipManager()->addMember(
            $ctx['institution'],
            $ctx['owner'],
            $otherTeacher,
            InstitutionMembershipRole::Teacher,
            'add_ot',
        );
        unset($otherTeacherMembership);

        $admin = $this->activeUser('advot-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);

        $delivery = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Classroom,
            $ctx['classroom'],
            null,
            $ctx['owner'],
            $opens,
            $closes,
            1,
            null,
            null,
            'create_v',
        );
        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_v');
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);

        self::assertTrue($this->decide($ctx['owner'], AssessmentDeliveryPermission::ACTIVATE, $delivery));
        self::assertTrue($this->decide($managerUser, AssessmentDeliveryPermission::CLOSE, $delivery));
        self::assertTrue($this->decide($ctx['teacher'], AssessmentDeliveryPermission::VIEW, $delivery));
        self::assertFalse($this->decide($otherTeacher, AssessmentDeliveryPermission::VIEW, $delivery));
        self::assertFalse($this->decide($staffUser, AssessmentDeliveryPermission::VIEW, $delivery));
        self::assertFalse($this->decide($admin, AssessmentDeliveryPermission::VIEW, $delivery));
        self::assertTrue($this->decide($ctx['student'], AssessmentDeliveryPermission::ACCESS_SELF, $delivery));
        self::assertFalse($this->decide($ctx['student'], AssessmentDeliveryPermission::ACTIVATE, $delivery));

        $sa = $this->users->find($ctx['sa']->getId());
        self::assertInstanceOf(User::class, $sa);
        self::assertTrue($this->decide($sa, AssessmentDeliveryPermission::CANCEL, $delivery));
    }

    private function decide(User $user, string $attribute, AssessmentDelivery $delivery): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $delivery);
    }
}

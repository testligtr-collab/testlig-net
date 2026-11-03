<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Classroom;
use App\Entity\HomeworkDefinition;
use App\Entity\User;
use App\Enum\InstitutionMembershipRole;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\HomeworkDefinitionPermission;
use App\Tests\Support\HomeworkTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Each scenario builds its own users: the voter reads request-scoped authorization snapshots,
 * so state is changed before a user's first decision.
 */
final class HomeworkDefinitionVoterTest extends KernelTestCase
{
    use HomeworkTestFixtures;

    private AccessDecisionManagerInterface $access;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $access = static::getContainer()->get(AccessDecisionManagerInterface::class);
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $access);
        $this->access = $access;
        $this->cleanupHomework();
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->cleanupHomework();
        }
        parent::tearDown();
    }

    public function testOwnerTeacherMatrix(): void
    {
        $ctx = $this->homeworkContext('hwv-matrix');
        $co = $this->addCoTeacher($ctx, 'hwv-matrix-co@example.com');
        $other = $this->homeworkContext('hwv-matrix-other');
        $admin = $this->activeUser('hwv-matrix-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $student = $this->activeUser('hwv-matrix-student@example.com');
        $this->membershipManager()->addMember($ctx['institution'], $ctx['owner'], $student, InstitutionMembershipRole::Student, 'add_student');
        $draft = $this->persistDraft($ctx, 'Kesirler');

        // Owner teacher.
        self::assertTrue($this->decide($ctx['teacher'], HomeworkDefinitionPermission::VIEW, $draft));
        self::assertTrue($this->decide($ctx['teacher'], HomeworkDefinitionPermission::EDIT, $draft));
        self::assertTrue($this->decide($ctx['teacher'], HomeworkDefinitionPermission::CREATE, $ctx['classroom']));

        // Co-teacher on the same classroom: own drafts only, never someone else's.
        self::assertFalse($this->decide($co['user'], HomeworkDefinitionPermission::VIEW, $draft));
        self::assertFalse($this->decide($co['user'], HomeworkDefinitionPermission::EDIT, $draft));
        self::assertTrue($this->decide($co['user'], HomeworkDefinitionPermission::CREATE, $ctx['classroom']));

        // Teacher of another institution.
        self::assertFalse($this->decide($other['teacher'], HomeworkDefinitionPermission::VIEW, $draft));
        self::assertFalse($this->decide($other['teacher'], HomeworkDefinitionPermission::EDIT, $draft));
        self::assertFalse($this->decide($other['teacher'], HomeworkDefinitionPermission::CREATE, $ctx['classroom']));

        // Hierarchy, institution owner and students never bypass.
        foreach ([$ctx['sa'], $admin, $ctx['owner'], $student] as $actor) {
            self::assertFalse($this->decide($actor, HomeworkDefinitionPermission::VIEW, $draft));
            self::assertFalse($this->decide($actor, HomeworkDefinitionPermission::EDIT, $draft));
            self::assertFalse($this->decide($actor, HomeworkDefinitionPermission::CREATE, $ctx['classroom']));
        }
    }

    public function testEndedAssignmentDeniesViewIncludingHistory(): void
    {
        $ctx = $this->homeworkContext('hwv-ended');
        $draft = $this->persistDraft($ctx, 'Geçmiş taslak');
        $this->teacherManager()->endAssignment($ctx['assignment'], $ctx['owner'], 'ended');

        self::assertFalse($this->decide($ctx['teacher'], HomeworkDefinitionPermission::VIEW, $draft));
        self::assertFalse($this->decide($ctx['teacher'], HomeworkDefinitionPermission::EDIT, $draft));
        self::assertFalse($this->decide($ctx['teacher'], HomeworkDefinitionPermission::CREATE, $ctx['classroom']));
    }

    public function testSuspendedMembershipDenies(): void
    {
        $ctx = $this->homeworkContext('hwv-susp');
        $draft = $this->persistDraft($ctx, 'Askıda');
        $this->em->getConnection()->executeStatement(
            "UPDATE institution_memberships SET status = 'suspended' WHERE id = :id",
            ['id' => $ctx['teacherMembership']->getId()->toBinary()],
        );

        self::assertFalse($this->decide($ctx['teacher'], HomeworkDefinitionPermission::VIEW, $draft));
        self::assertFalse($this->decide($ctx['teacher'], HomeworkDefinitionPermission::CREATE, $ctx['classroom']));
    }

    public function testInactiveAndUnverifiedUsersAreDenied(): void
    {
        $inactive = $this->homeworkContext('hwv-inactive');
        $inactiveDraft = $this->persistDraft($inactive, 'Pasif');
        $this->setUserStatusRaw($inactive['teacher'], UserStatus::Suspended);
        self::assertFalse($this->decide($inactive['teacher'], HomeworkDefinitionPermission::VIEW, $inactiveDraft));

        $unverified = $this->homeworkContext('hwv-unverified');
        $unverifiedDraft = $this->persistDraft($unverified, 'Doğrulanmamış');
        $this->clearEmailVerificationRaw($unverified['teacher']);
        self::assertFalse($this->decide($unverified['teacher'], HomeworkDefinitionPermission::VIEW, $unverifiedDraft));
    }

    public function testSuspendedInstitutionDenies(): void
    {
        $ctx = $this->homeworkContext('hwv-inst');
        $draft = $this->persistDraft($ctx, 'Kurum askıda');
        $this->institutionStatus()->suspend($ctx['institution'], $ctx['sa'], 'suspend');

        self::assertFalse($this->decide($ctx['teacher'], HomeworkDefinitionPermission::VIEW, $draft));
    }

    public function testUnsupportedSubjectsAndAnonymousFailClosed(): void
    {
        $ctx = $this->homeworkContext('hwv-closed');
        $draft = $this->persistDraft($ctx, 'Kapalı');

        // CREATE needs a Classroom, VIEW/EDIT need a HomeworkDefinition: mismatches are never granted.
        self::assertFalse($this->decide($ctx['teacher'], HomeworkDefinitionPermission::CREATE, $draft));
        self::assertFalse($this->decide($ctx['teacher'], HomeworkDefinitionPermission::VIEW, $ctx['classroom']));
        self::assertFalse($this->decide($ctx['teacher'], 'HOMEWORK_DEFINITION_PUBLISH', $draft));
    }

    private function decide(User $user, string $attribute, HomeworkDefinition|Classroom $subject): bool
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return $this->access->decide($token, [$attribute], $subject);
    }
}

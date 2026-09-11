<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssessmentScoringFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\TeacherAssignmentStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AssessmentScoringException;
use App\Tests\Support\AssessmentScoringTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * Identity-map proofs for AssessmentResultReader / AccessGate.
 * Managed entities stay stale after out-of-band DBAL updates (no detach/clear).
 */
final class AssessmentResultReaderFreshStaleTest extends KernelTestCase
{
    use AssessmentScoringTestFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        if (isset($this->em)) {
            $this->cleanupDeliveryFixtures();
        }
        parent::tearDown();
    }

    public function testStaleActorSuspendedDeniesRead(): void
    {
        [$attempt, $actor] = $this->releasedAttemptAndStudentActor('arrfs1');
        self::assertSame(UserStatus::Active, $actor->getStatus());

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $actor->getId()->toBinary()],
        );
        self::assertSame(UserStatus::Active, $actor->getStatus(), 'Precondition: managed still Active');

        $this->expectReadDenied($actor, $attempt->getId());
    }

    public function testStaleActorArchivedDeniesRead(): void
    {
        [$attempt, $actor] = $this->releasedAttemptAndStudentActor('arrfs2');
        self::assertSame(UserStatus::Active, $actor->getStatus());

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Archived->value, $actor->getId()->toBinary()],
        );
        self::assertSame(UserStatus::Active, $actor->getStatus(), 'Precondition: managed still Active');

        $this->expectReadDenied($actor, $attempt->getId());
    }

    public function testStaleEmailUnverifiedDeniesRead(): void
    {
        [$attempt, $actor] = $this->releasedAttemptAndStudentActor('arrfs3');
        self::assertNotNull($actor->getEmailVerifiedAt());

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$actor->getId()->toBinary()],
        );
        self::assertNotNull($actor->getEmailVerifiedAt(), 'Precondition: managed still verified');

        $this->expectReadDenied($actor, $attempt->getId());
    }

    public function testStaleSuperAdminRoleRemovedDeniesRead(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('arrfs4');
        $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'rel_sa',
        );

        $sa = $this->reloadUser($fx['sa']->getId());
        self::assertContains(UserRole::SuperAdmin->value, $sa->getRoles());

        $roles = array_values(array_filter(
            $sa->getRoles(),
            static fn (string $role): bool => UserRole::SuperAdmin->value !== $role,
        ));
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET global_roles = ? WHERE id = ?',
            [json_encode($roles, \JSON_THROW_ON_ERROR), $sa->getId()->toBinary()],
        );
        self::assertContains(
            UserRole::SuperAdmin->value,
            $sa->getRoles(),
            'Precondition: managed still SUPER_ADMIN',
        );

        $this->expectReadDenied($sa, $attempt->getId());
    }

    public function testStaleStudentMembershipSuspendedDeniesRead(): void
    {
        [$attempt, $actor, $fx] = $this->releasedAttemptStudentAndFixture('arrfs5');
        self::assertSame(InstitutionMembershipStatus::Active, $fx['studentMembership']->getStatus());

        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            [InstitutionMembershipStatus::Suspended->value, $fx['studentMembership']->getId()->toBinary()],
        );
        self::assertSame(
            InstitutionMembershipStatus::Active,
            $fx['studentMembership']->getStatus(),
            'Precondition: managed membership still Active',
        );

        $this->expectReadDenied($actor, $attempt->getId());
    }

    public function testStaleTeacherRoleChangedToStaffDeniesRead(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('arrfs6');
        $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'rel_tch',
        );

        $teacher = $this->reloadUser($fx['teacher']->getId());
        self::assertSame(InstitutionMembershipRole::Teacher, $fx['teacherMembership']->getRole());

        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET role = ? WHERE id = ?',
            [InstitutionMembershipRole::Staff->value, $fx['teacherMembership']->getId()->toBinary()],
        );
        self::assertSame(
            InstitutionMembershipRole::Teacher,
            $fx['teacherMembership']->getRole(),
            'Precondition: managed still Teacher',
        );

        $this->expectReadDenied($teacher, $attempt->getId());
    }

    public function testStaleTeacherAssignmentEndedDeniesRead(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('arrfs7');
        $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'rel_asg',
        );

        $teacher = $this->reloadUser($fx['teacher']->getId());
        $updated = $this->em->getConnection()->executeStatement(
            'UPDATE classroom_teacher_assignments
                SET status = ?
              WHERE classroom_id = ? AND teacher_membership_id = ? AND status = ?',
            [
                TeacherAssignmentStatus::Ended->value,
                $fx['classroom']->getId()->toBinary(),
                $fx['teacherMembership']->getId()->toBinary(),
                TeacherAssignmentStatus::Active->value,
            ],
        );
        self::assertSame(1, $updated);

        // Prove identity map was not refreshed for the teacher user itself.
        self::assertSame(UserStatus::Active, $teacher->getStatus(), 'Precondition: managed teacher still Active');

        $this->expectReadDenied($teacher, $attempt->getId());
    }

    public function testStaleInstitutionSuspendedDeniesRead(): void
    {
        [$attempt, $actor, $fx] = $this->releasedAttemptStudentAndFixture('arrfs8');
        self::assertSame(InstitutionStatus::Active, $fx['institution']->getStatus());

        $this->em->getConnection()->executeStatement(
            'UPDATE institutions SET status = ? WHERE id = ?',
            [InstitutionStatus::Suspended->value, $fx['institution']->getId()->toBinary()],
        );
        self::assertSame(
            InstitutionStatus::Active,
            $fx['institution']->getStatus(),
            'Precondition: managed institution still Active',
        );

        $this->expectReadDenied($actor, $attempt->getId());
    }

    public function testFreshActiveStudentSeesOwnReleasedResult(): void
    {
        [$attempt, $actor] = $this->releasedAttemptAndStudentActor('arrfs9');
        $view = $this->resultReader()->readReleasedResult(
            $actor,
            $this->reloadAttempt($attempt->getId()),
        );
        self::assertTrue($view->getAttemptId()->equals($attempt->getId()));
    }

    public function testOtherStudentDenied(): void
    {
        [$attempt, , $fx] = $this->releasedAttemptStudentAndFixture('arrfs10');
        $other = $this->activeUser('arrfs10-other@example.com');
        $this->membershipManager()->addMember(
            $fx['institution'],
            $fx['owner'],
            $other,
            InstitutionMembershipRole::Student,
            'add_other',
        );

        $this->expectReadDenied($this->reloadUser($other->getId()), $attempt->getId());
    }

    public function testAdminWithoutMembershipDenied(): void
    {
        [$attempt] = $this->releasedAttemptAndStudentActor('arrfs11');
        $admin = $this->activeUser('arrfs11-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);

        $this->expectReadDenied($this->reloadUser($admin->getId()), $attempt->getId());
    }

    public function testModeratorWithoutMembershipDenied(): void
    {
        [$attempt] = $this->releasedAttemptAndStudentActor('arrfs12');
        $moderator = $this->activeUser('arrfs12-mod@example.com', UserRole::Moderator);

        $this->expectReadDenied($this->reloadUser($moderator->getId()), $attempt->getId());
    }

    /**
     * @return array{0: \App\Entity\AssessmentAttempt, 1: \App\Entity\User}
     */
    private function releasedAttemptAndStudentActor(string $prefix): array
    {
        [$attempt, $actor] = $this->releasedAttemptStudentAndFixture($prefix);

        return [$attempt, $actor];
    }

    /**
     * @return array{0: \App\Entity\AssessmentAttempt, 1: \App\Entity\User, 2: array<string, mixed>}
     */
    private function releasedAttemptStudentAndFixture(string $prefix): array
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt($prefix);
        $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'rel_'.$prefix,
        );
        $actor = $this->reloadUser($fx['student']->getId());

        return [$this->reloadAttempt($attempt->getId()), $actor, $fx];
    }

    private function expectReadDenied(\App\Entity\User $actor, Uuid $attemptId): void
    {
        try {
            $this->resultReader()->readReleasedResult(
                $actor,
                $this->reloadAttempt($attemptId),
            );
            self::fail('Expected result read denied.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::Unauthorized, $e->getReason());
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssessmentScoringFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\ScoringRunStatus;
use App\Enum\UserRole;
use App\Exception\AssessmentScoringException;
use App\Tests\Support\AssessmentScoringTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

final class ManualAssessmentGradingManagerTest extends KernelTestCase
{
    use AssessmentScoringTestFixtures;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
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

    public function testOwnerManagerAssignedTeacherCanGrade(): void
    {
        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('mag1');
        unset($attempt);

        $ownerRun = $this->manualGrading()->gradeItem(
            $this->reloadScoringRun($run->getId()),
            $item,
            $this->reloadUser($fx['owner']->getId()),
            '2.00',
            'grade_owner',
        );
        self::assertSame(ScoringRunStatus::Completed, $ownerRun->getStatus());

        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('mag1b');
        unset($attempt);
        $manager = $this->activeUser('mag1b-mgr@example.com');
        $this->membershipManager()->addMember(
            $fx['institution'],
            $fx['owner'],
            $manager,
            InstitutionMembershipRole::Manager,
            'add_mgr',
        );
        $mgrRun = $this->manualGrading()->gradeItem(
            $this->reloadScoringRun($run->getId()),
            $item,
            $this->reloadUser($manager->getId()),
            '1.50',
            'grade_mgr',
        );
        self::assertSame(ScoringRunStatus::Completed, $mgrRun->getStatus());

        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('mag1c');
        unset($attempt);
        $teacherRun = $this->manualGrading()->gradeItem(
            $this->reloadScoringRun($run->getId()),
            $item,
            $this->reloadUser($fx['teacher']->getId()),
            '2.50',
            'grade_teacher',
        );
        self::assertSame(ScoringRunStatus::Completed, $teacherRun->getStatus());
    }

    public function testStaffStudentUnassignedAdminModeratorDenied(): void
    {
        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('mag2');
        $runId = $run->getId();
        $itemId = $item->getId();
        unset($attempt);

        $staff = $this->activeUser('mag2-staff@example.com');
        $institution = $this->em->find(\App\Entity\Institution::class, $fx['institution']->getId());
        self::assertInstanceOf(\App\Entity\Institution::class, $institution);
        $this->membershipManager()->addMember(
            $institution,
            $this->reloadUser($fx['owner']->getId()),
            $staff,
            InstitutionMembershipRole::Staff,
            'add_staff',
        );
        $this->expectUnauthorizedGrade($runId, $itemId, $staff->getId());
        $this->resetDoctrineDelivery();

        $run = $this->reloadScoringRun($runId);
        $fxStudentId = $fx['student']->getId();
        $fxOwnerId = $fx['owner']->getId();
        $fxInstitutionId = $fx['institution']->getId();
        $this->expectUnauthorizedGrade($run->getId(), $itemId, $fxStudentId);
        $this->resetDoctrineDelivery();

        $unassigned = $this->activeUser('mag2-un@example.com');
        $institutionForUnassigned = $this->em->find(\App\Entity\Institution::class, $fxInstitutionId);
        self::assertInstanceOf(\App\Entity\Institution::class, $institutionForUnassigned);
        $this->membershipManager()->addMember(
            $institutionForUnassigned,
            $this->reloadUser($fxOwnerId),
            $unassigned,
            InstitutionMembershipRole::Teacher,
            'add_un',
        );
        $this->expectUnauthorizedGrade($runId, $itemId, $unassigned->getId());
        $this->resetDoctrineDelivery();

        $admin = $this->activeUser('mag2-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $this->expectUnauthorizedGrade($runId, $itemId, $admin->getId());
        $this->resetDoctrineDelivery();

        $moderator = $this->activeUser('mag2-mod@example.com', UserRole::Moderator);
        $this->expectUnauthorizedGrade($runId, $itemId, $moderator->getId());
    }

    public function testSuperAdminCanGradeWithoutMembership(): void
    {
        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('mag3');
        unset($attempt, $fx);
        $sa = $this->activeUser('mag3-sa-'.bin2hex(random_bytes(4)).'@example.com');
        $sa->addGlobalRole(UserRole::SuperAdmin);
        $this->users->save($sa);
        $graded = $this->manualGrading()->gradeItem(
            $this->reloadScoringRun($run->getId()),
            $item,
            $this->reloadUser($sa->getId()),
            '2.00',
            'grade_sa',
        );
        self::assertSame(ScoringRunStatus::Completed, $graded->getStatus());
    }

    public function testStaleMembershipDenied(): void
    {
        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('mag4');
        unset($attempt);
        $teacher = $this->reloadUser($fx['teacher']->getId());
        self::assertSame(InstitutionMembershipStatus::Active, $fx['teacherMembership']->getStatus());

        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            [InstitutionMembershipStatus::Suspended->value, $fx['teacherMembership']->getId()->toBinary()],
        );
        self::assertSame(InstitutionMembershipStatus::Active, $fx['teacherMembership']->getStatus());

        try {
            $this->manualGrading()->gradeItem(
                $this->reloadScoringRun($run->getId()),
                $item,
                $teacher,
                '1.00',
                'grade_stale',
            );
            self::fail('Expected stale membership denied.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testPointsBoundsAndAppendOnlyDecisionHistory(): void
    {
        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        [$attempt, $run, $fx, $item] = $this->submitAndScorePendingManual('mag5');
        unset($attempt);
        $owner = $this->reloadUser($fx['owner']->getId());
        $item = $this->em->find(\App\Entity\AssessmentAttemptItem::class, $item->getId());
        self::assertInstanceOf(\App\Entity\AssessmentAttemptItem::class, $item);

        try {
            $this->manualGrading()->gradeItem(
                $this->reloadScoringRun($run->getId()),
                $item,
                $owner,
                '-0.01',
                'grade_neg',
            );
            self::fail('Expected negative points rejected.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::InvalidInput, $e->getReason());
        }

        // InvalidInput during normalize happens before flush — EM should stay open.
        if (!$this->em->isOpen()) {
            $this->resetDoctrineDelivery();
            $run = $this->reloadScoringRun($run->getId());
            $item = $this->em->find(\App\Entity\AssessmentAttemptItem::class, $item->getId());
            self::assertInstanceOf(\App\Entity\AssessmentAttemptItem::class, $item);
            $owner = $this->reloadUser($fx['owner']->getId());
        }

        try {
            $this->manualGrading()->gradeItem(
                $this->reloadScoringRun($run->getId()),
                $item,
                $owner,
                '9.99',
                'grade_over',
            );
            self::fail('Expected over-maximum rejected.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::InvalidInput, $e->getReason());
        }

        if (!$this->em->isOpen()) {
            $this->resetDoctrineDelivery();
            $item = $this->em->find(\App\Entity\AssessmentAttemptItem::class, $item->getId());
            self::assertInstanceOf(\App\Entity\AssessmentAttemptItem::class, $item);
            $owner = $this->reloadUser($fx['owner']->getId());
        }

        $graded = $this->manualGrading()->gradeItem(
            $this->reloadScoringRun($run->getId()),
            $item,
            $owner,
            '2.00',
            'grade_ok',
        );
        self::assertSame(ScoringRunStatus::Completed, $graded->getStatus());

        $decisions = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_manual_grade_decisions WHERE attempt_item_id = ?',
            [$item->getId()->toBinary()],
        );
        self::assertSame(1, $decisions);

        try {
            $this->em->getConnection()->executeStatement(
                'UPDATE assessment_manual_grade_decisions SET awarded_points = ? WHERE attempt_item_id = ?',
                ['1.00', $item->getId()->toBinary()],
            );
            self::fail('Expected decision UPDATE blocked.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }

        try {
            $this->em->getConnection()->executeStatement(
                'DELETE FROM assessment_manual_grade_decisions WHERE attempt_item_id = ?',
                [$item->getId()->toBinary()],
            );
            self::fail('Expected decision DELETE blocked.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    private function expectUnauthorizedGrade(
        \Symfony\Component\Uid\Uuid $runId,
        \Symfony\Component\Uid\Uuid $itemId,
        \Symfony\Component\Uid\Uuid $actorId,
    ): void {
        $run = $this->reloadScoringRun($runId);
        $item = $this->em->find(\App\Entity\AssessmentAttemptItem::class, $itemId);
        self::assertInstanceOf(\App\Entity\AssessmentAttemptItem::class, $item);
        try {
            $this->manualGrading()->gradeItem(
                $run,
                $item,
                $this->reloadUser($actorId),
                '1.00',
                'grade_deny',
            );
            self::fail('Expected unauthorized grading.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::Unauthorized, $e->getReason());
        }
    }
}

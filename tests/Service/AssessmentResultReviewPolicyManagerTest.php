<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssessmentResultReviewFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\ResultReviewAvailabilityMode;
use App\Enum\ResultReviewPolicyStatus;
use App\Exception\AssessmentResultReviewException;
use App\Tests\Support\AssessmentResultReviewTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentResultReviewPolicyManagerTest extends KernelTestCase
{
    use AssessmentResultReviewTestFixtures;

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

    public function testCreateDraftActivateAndGuard(): void
    {
        $fx = $this->activatedClassroomDelivery('arrpm1');
        $owner = $this->reloadUser($fx['owner']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        $draft = $this->reviewPolicies()->createDraft(
            $delivery,
            $owner,
            ResultReviewAvailabilityMode::AfterDeliveryClosed,
            null,
            true,
            true,
            false,
            false,
            false,
            'create_arrpm1',
        );
        self::assertSame(ResultReviewPolicyStatus::Draft, $draft->getStatus());
        self::assertSame(1, $draft->getVersion());
        self::assertNull($this->reviewPolicyGuardRepo()->findOneByDeliveryId($delivery->getId()));

        $active = $this->reviewPolicies()->activate(
            $this->reloadReviewPolicy($draft->getId()),
            $owner,
            'activate_arrpm1',
        );
        self::assertSame(ResultReviewPolicyStatus::Active, $active->getStatus());
        $guard = $this->reviewPolicyGuardRepo()->findOneByDeliveryId($delivery->getId());
        self::assertNotNull($guard);
        self::assertTrue($guard->getPolicy()->getId()->equals($active->getId()));
    }

    public function testActivateSupersedesPreviousActive(): void
    {
        $fx = $this->activatedClassroomDelivery('arrpm2');
        $owner = $this->reloadUser($fx['owner']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        $first = $this->activateFullReviewPolicy($delivery, $owner, 'arrpm2a');
        $secondDraft = $this->reviewPolicies()->createNewVersion(
            $this->reloadReviewPolicy($first->getId()),
            $owner,
            'clone_arrpm2',
            showStudentAnswer: false,
        );
        self::assertSame(2, $secondDraft->getVersion());

        $second = $this->reviewPolicies()->activate(
            $this->reloadReviewPolicy($secondDraft->getId()),
            $owner,
            'activate_arrpm2b',
        );
        $first = $this->reloadReviewPolicy($first->getId());
        self::assertSame(ResultReviewPolicyStatus::Superseded, $first->getStatus());
        self::assertSame(ResultReviewPolicyStatus::Active, $second->getStatus());
        $guard = $this->reviewPolicyGuardRepo()->findOneByDeliveryId($delivery->getId());
        self::assertNotNull($guard);
        self::assertTrue($guard->getPolicy()->getId()->equals($second->getId()));
    }

    public function testNeverModeRejectsItemFlags(): void
    {
        $fx = $this->activatedClassroomDelivery('arrpm3');
        $owner = $this->reloadUser($fx['owner']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        try {
            $this->reviewPolicies()->createDraft(
                $delivery,
                $owner,
                ResultReviewAvailabilityMode::Never,
                null,
                true,
                true,
                false,
                false,
                false,
                'bad_never',
            );
            self::fail('Expected invalid_input');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::InvalidInput, $e->getReason());
        }
    }

    public function testScheduledAfterCloseRequiresScheduledAt(): void
    {
        $fx = $this->activatedClassroomDelivery('arrpm4');
        $owner = $this->reloadUser($fx['owner']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        try {
            $this->reviewPolicies()->createDraft(
                $delivery,
                $owner,
                ResultReviewAvailabilityMode::ScheduledAfterClose,
                null,
                true,
                true,
                false,
                false,
                false,
                'bad_sched',
            );
            self::fail('Expected invalid_input');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::InvalidInput, $e->getReason());
        }
    }

    public function testTeacherCannotManagePolicy(): void
    {
        $fx = $this->activatedClassroomDelivery('arrpm5');
        $teacher = $this->reloadUser($fx['teacher']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        try {
            $this->reviewPolicies()->createDraft(
                $delivery,
                $teacher,
                ResultReviewAvailabilityMode::Never,
                null,
                true,
                false,
                false,
                false,
                false,
                'teacher_deny',
            );
            self::fail('Expected unauthorized');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }
        self::assertSame(InstitutionMembershipRole::Teacher, $fx['teacherMembership']->getRole());
    }

    public function testUpdateDraftOnly(): void
    {
        $fx = $this->activatedClassroomDelivery('arrpm6');
        $owner = $this->reloadUser($fx['owner']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        $draft = $this->reviewPolicies()->createDraft(
            $delivery,
            $owner,
            ResultReviewAvailabilityMode::Never,
            null,
            true,
            false,
            false,
            false,
            false,
            'draft_arrpm6',
        );
        $updated = $this->reviewPolicies()->updateDraft(
            $this->reloadReviewPolicy($draft->getId()),
            $owner,
            ResultReviewAvailabilityMode::AfterDeliveryClosed,
            null,
            true,
            true,
            true,
            false,
            false,
            'update_arrpm6',
        );
        self::assertTrue($updated->showStudentAnswer());
        self::assertSame(ResultReviewAvailabilityMode::AfterDeliveryClosed, $updated->getAvailabilityMode());

        $active = $this->reviewPolicies()->activate(
            $this->reloadReviewPolicy($updated->getId()),
            $owner,
            'activate_arrpm6',
        );
        try {
            $this->reviewPolicies()->updateDraft(
                $this->reloadReviewPolicy($active->getId()),
                $owner,
                ResultReviewAvailabilityMode::Never,
                null,
                true,
                false,
                false,
                false,
                false,
                'update_active',
            );
            self::fail('Expected invalid_transition');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::InvalidTransition, $e->getReason());
        }
    }

    public function testSensitiveFlagsRequireItemOutcomes(): void
    {
        $fx = $this->activatedClassroomDelivery('arrpm7');
        $owner = $this->reloadUser($fx['owner']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        try {
            $this->reviewPolicies()->createDraft(
                $delivery,
                $owner,
                ResultReviewAvailabilityMode::AfterDeliveryClosed,
                null,
                true,
                false,
                false,
                true,
                false,
                'bad_flags',
            );
            self::fail('Expected invalid_input');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::InvalidInput, $e->getReason());
        }
    }
}

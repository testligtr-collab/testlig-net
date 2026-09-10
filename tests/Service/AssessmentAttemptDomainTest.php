<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Institution;
use App\Entity\User;
use App\Enum\AssessmentAttemptFailureReason;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionType;
use App\Enum\SecurityAuditAction;
use App\Enum\UserStatus;
use App\Exception\AssessmentAttemptException;
use App\Tests\Support\AssessmentAttemptTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentAttemptDomainTest extends KernelTestCase
{
    use AssessmentAttemptTestFixtures;

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

    public function testStudentStartMaterializesItemsGuardAndAudit(): void
    {
        $fx = $this->activatedClassroomDelivery('aad1');
        $student = $this->reloadUser($fx['student']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        $attempt = $this->attempts()->startAttempt($delivery, $student, 'start_ok');
        self::assertSame(AssessmentAttemptStatus::InProgress, $attempt->getStatus());
        self::assertSame(1, $attempt->getAttemptNumber());

        $items = $this->attemptItems()->findItemsForAttemptOrdered($attempt->getId());
        self::assertGreaterThan(0, \count($items));

        $guard = $this->activeGuards()->findForRecipient($fx['recipient']->getId());
        self::assertNotNull($guard);
        self::assertTrue($guard->getAttempt()->getId()->equals($attempt->getId()));

        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AssessmentAttemptStarted->value));
    }

    public function testSecondStartWhileInProgressDenied(): void
    {
        $fx = $this->activatedClassroomDelivery('aad2');
        $student = $this->reloadUser($fx['student']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $this->attempts()->startAttempt($delivery, $student, 'start_1');

        try {
            $this->attempts()->startAttempt($delivery, $student, 'start_2');
            self::fail('Expected active attempt exists.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::ActiveAttemptExists, $e->getReason());
        }
    }

    public function testSubmitThenRestartUntilMaxAttempts(): void
    {
        $fx = $this->activatedClassroomDelivery('aad3', 2);
        $student = $this->reloadUser($fx['student']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        $first = $this->attempts()->startAttempt($delivery, $student, 'start_a1');
        self::assertSame(1, $first->getAttemptNumber());
        $this->attempts()->submit($first, $student, 'submit_a1');
        $first = $this->reloadAttempt($first->getId());
        self::assertSame(AssessmentAttemptStatus::Submitted, $first->getStatus());
        self::assertNull($this->activeGuards()->findForRecipient($fx['recipient']->getId()));

        $second = $this->attempts()->startAttempt($delivery, $student, 'start_a2');
        self::assertSame(2, $second->getAttemptNumber());
        $this->attempts()->submit($second, $student, 'submit_a2');

        try {
            $this->attempts()->startAttempt($delivery, $student, 'start_a3');
            self::fail('Expected attempt quota exceeded.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::AttemptQuotaExceeded, $e->getReason());
        }
    }

    public function testInactiveUnverifiedRevokedMembershipWindowAndDeliveryDenials(): void
    {
        // Inactive user
        $fx = $this->activatedClassroomDelivery('aad4a');
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $fx['student']->getId()->toBinary()],
        );
        try {
            $this->attempts()->startAttempt(
                $this->reloadDelivery($fx['delivery']->getId()),
                $this->reloadUser($fx['student']->getId()),
                'deny_inactive',
            );
            self::fail('Expected user inactive.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::UserInactive, $e->getReason());
        }
        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        // Unverified email
        $fx = $this->activatedClassroomDelivery('aad4b');
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$fx['student']->getId()->toBinary()],
        );
        try {
            $this->attempts()->startAttempt(
                $this->reloadDelivery($fx['delivery']->getId()),
                $this->reloadUser($fx['student']->getId()),
                'deny_unv',
            );
            self::fail('Expected email not verified.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::EmailNotVerified, $e->getReason());
        }
        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        // Revoked recipient
        $fx = $this->activatedClassroomDelivery('aad4c');
        $this->deliveries()->revokeRecipient(
            $this->reloadDelivery($fx['delivery']->getId()),
            $fx['recipient'],
            $fx['owner'],
            'revoke_r',
        );
        try {
            $this->attempts()->startAttempt(
                $this->reloadDelivery($fx['delivery']->getId()),
                $this->reloadUser($fx['student']->getId()),
                'deny_rev',
            );
            self::fail('Expected recipient revoked.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::RecipientRevoked, $e->getReason());
        }
        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        // Inactive membership
        $fx = $this->activatedClassroomDelivery('aad4d');
        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            ['suspended', $fx['studentMembership']->getId()->toBinary()],
        );
        try {
            $this->attempts()->startAttempt(
                $this->reloadDelivery($fx['delivery']->getId()),
                $this->reloadUser($fx['student']->getId()),
                'deny_mem',
            );
            self::fail('Expected membership inactive.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::MembershipInactive, $e->getReason());
        }
        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        // Window before opens
        $fx = $this->publishedDeliveryContext('aad4e');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $delivery = $this->deliveries()->createDraft(
            $fx['institution'],
            $fx['publication'],
            AssessmentDeliveryAudienceType::Classroom,
            $fx['classroom'],
            null,
            $fx['owner'],
            $now->modify('+1 day'),
            $now->modify('+7 days'),
            1,
            null,
            null,
            'create_future',
        );
        $this->deliveries()->activate($delivery, $fx['owner'], 'activate_future');
        try {
            $this->attempts()->startAttempt(
                $this->reloadDelivery($delivery->getId()),
                $this->reloadUser($fx['student']->getId()),
                'deny_future',
            );
            self::fail('Expected not open yet.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::NotOpenYet, $e->getReason());
        }
        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        // Window after closes (clock advance; opens/closes are immutable)
        $mock = new MockClock();
        Clock::set($mock);
        $fx = $this->activatedClassroomDelivery('aad4f', 1);
        $mock->modify('+10 days');
        try {
            $this->attempts()->startAttempt(
                $this->reloadDelivery($fx['delivery']->getId()),
                $this->reloadUser($fx['student']->getId()),
                'deny_closed_window',
            );
            self::fail('Expected delivery window closed.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::DeliveryWindowClosed, $e->getReason());
        }
        Clock::set(new NativeClock());
        $this->resetDoctrineDelivery();
        $this->cleanupDeliveryFixtures();

        // Inactive delivery
        $fx = $this->activatedClassroomDelivery('aad4g');
        $this->deliveries()->close($this->reloadDelivery($fx['delivery']->getId()), $fx['owner'], 'close_d');
        try {
            $this->attempts()->startAttempt(
                $this->reloadDelivery($fx['delivery']->getId()),
                $this->reloadUser($fx['student']->getId()),
                'deny_closed',
            );
            self::fail('Expected delivery not active.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::DeliveryNotActive, $e->getReason());
        }
    }

    public function testCrossTenantStudentCannotStart(): void
    {
        $fx = $this->activatedClassroomDelivery('aad5');
        $otherOwner = $this->activeUser('aad5-other-owner@example.com');
        $otherInst = $this->institutionCreator()->create(
            $fx['sa'],
            $otherOwner,
            'aad5 Other School',
            InstitutionType::School,
            'platform_setup',
        );
        self::assertInstanceOf(Institution::class, $otherInst);
        $this->institutionStatus()->activate($otherInst, $fx['sa'], 'activate');
        $otherStudent = $this->activeUser('aad5-other-stu@example.com');
        $this->membershipManager()->addMember(
            $otherInst,
            $otherOwner,
            $otherStudent,
            InstitutionMembershipRole::Student,
            'add_other',
        );

        try {
            $this->attempts()->startAttempt(
                $this->reloadDelivery($fx['delivery']->getId()),
                $this->reloadUser($otherStudent->getId()),
                'cross_tenant',
            );
            self::fail('Expected recipient not found.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::RecipientNotFound, $e->getReason());
        }
    }

    public function testSubmitSuccessAndSaveAnswerAfterSubmitDenied(): void
    {
        $fx = $this->activatedClassroomDelivery('aad6');
        $student = $this->reloadUser($fx['student']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $attempt = $this->attempts()->startAttempt($delivery, $student, 'start_sub');
        $item = $this->firstAttemptItem($attempt);

        $this->attempts()->submit($attempt, $student, 'submit_ok');
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AssessmentAttemptSubmitted->value));

        $attempt = $this->reloadAttempt($attempt->getId());
        try {
            $this->attempts()->saveAnswer(
                $attempt,
                $item,
                $student,
                $this->singleChoicePayload(),
                0,
                'save_after_submit',
            );
            self::fail('Expected attempt terminal.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::AttemptTerminal, $e->getReason());
        }
    }

    public function testExpireViaMockClock(): void
    {
        // expires_at is immutable (BU trigger); advance injected ClockInterface instead.
        $mock = new MockClock();
        Clock::set($mock);

        $fx = $this->activatedClassroomDelivery('aad7');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_exp',
        );
        self::assertSame(AssessmentAttemptStatus::InProgress, $attempt->getStatus());

        $mock->modify('+2 hours');
        $this->attempts()->expire($attempt, 'system_expire');
        $attempt = $this->reloadAttempt($attempt->getId());
        self::assertSame(AssessmentAttemptStatus::Expired, $attempt->getStatus());
        self::assertNotNull($attempt->getExpiredAt());
        self::assertNull($this->activeGuards()->findForRecipient($fx['recipient']->getId()));
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AssessmentAttemptExpired->value));
    }

    public function testOwnerCanCancelStudentAndTeacherCannot(): void
    {
        $fx = $this->activatedClassroomDelivery('aad8');
        $studentId = $fx['student']->getId();
        $teacherId = $fx['teacher']->getId();
        $ownerId = $fx['owner']->getId();
        $recipientId = $fx['recipient']->getId();
        $deliveryId = $fx['delivery']->getId();

        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($deliveryId),
            $this->reloadUser($studentId),
            'start_can',
        );
        $attemptId = $attempt->getId();

        try {
            $this->attempts()->cancel($attempt, $this->reloadUser($studentId), 'cancel_stu', 'student_request');
            self::fail('Expected student cancel unauthorized.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::Unauthorized, $e->getReason());
        }
        $this->resetDoctrineDelivery();

        try {
            $this->attempts()->cancel(
                $this->reloadAttempt($attemptId),
                $this->reloadUser($teacherId),
                'cancel_tch',
                'teacher_request',
            );
            self::fail('Expected teacher cancel unauthorized.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::Unauthorized, $e->getReason());
        }
        $this->resetDoctrineDelivery();

        $this->attempts()->cancel(
            $this->reloadAttempt($attemptId),
            $this->reloadUser($ownerId),
            'cancel_own',
            'owner_cancel',
        );
        $attempt = $this->reloadAttempt($attemptId);
        self::assertSame(AssessmentAttemptStatus::Cancelled, $attempt->getStatus());
        self::assertNull($this->activeGuards()->findForRecipient($recipientId));
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AssessmentAttemptCancelled->value));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssessmentResultReviewFailureReason;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionStatus;
use App\Enum\UserStatus;
use App\Exception\AssessmentResultReviewException;
use App\Tests\Support\AssessmentResultReviewTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentResultReviewFreshStaleTest extends KernelTestCase
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

    public function testStaleSuspendedActorDeniesReview(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arrfs1',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );
        $actor = $this->reloadUser($fx['student']->getId());
        self::assertSame(UserStatus::Active, $actor->getStatus());

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $actor->getId()->toBinary()],
        );
        self::assertSame(UserStatus::Active, $actor->getStatus(), 'Precondition: managed still Active');

        try {
            $this->reviewReader()->readReview($actor, $this->reloadAttempt($attempt->getId()));
            self::fail('Expected unauthorized');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testStaleMembershipRevokedDeniesManage(): void
    {
        $fx = $this->activatedClassroomDelivery('arrfs2');
        $owner = $this->reloadUser($fx['owner']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $membershipId = $fx['owner']->getId(); // wrong - need owner membership

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT id FROM institution_memberships WHERE user_id = ? AND institution_id = ? LIMIT 1',
            [$owner->getId()->toBinary(), $fx['institution']->getId()->toBinary()],
        );
        self::assertIsArray($row);

        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            [InstitutionMembershipStatus::Ended->value, $row['id']],
        );

        try {
            $this->reviewPolicies()->createDraft(
                $delivery,
                $owner,
                \App\Enum\ResultReviewAvailabilityMode::Never,
                null,
                true,
                false,
                false,
                false,
                false,
                'stale_mgr',
            );
            self::fail('Expected unauthorized');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }
        unset($membershipId);
    }

    public function testStaleInstitutionInactiveDeniesReview(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arrfs3',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );
        $student = $this->reloadUser($fx['student']->getId());

        $this->em->getConnection()->executeStatement(
            'UPDATE institutions SET status = ? WHERE id = ?',
            [InstitutionStatus::Suspended->value, $fx['institution']->getId()->toBinary()],
        );

        try {
            $this->reviewReader()->readReview($student, $this->reloadAttempt($attempt->getId()));
            self::fail('Expected unauthorized');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }
    }
}

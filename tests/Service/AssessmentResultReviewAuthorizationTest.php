<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\Institution;
use App\Enum\AssessmentDeliveryRecipientStatus;
use App\Enum\AssessmentResultReviewFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionMembershipStatus;
use App\Enum\InstitutionType;
use App\Enum\ResultReviewAvailabilityMode;
use App\Enum\UserRole;
use App\Exception\AssessmentResultReviewException;
use App\Tests\Support\AssessmentResultReviewTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

/**
 * Authorization hardening for StudentResultReviewView (no SUPER_ADMIN read override).
 */
final class AssessmentResultReviewAuthorizationTest extends KernelTestCase
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

    public function testSuperAdminCannotReadOtherStudentReviewDto(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arra1',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );

        try {
            $this->reviewReader()->readReview(
                $this->reloadUser($fx['sa']->getId()),
                $this->reloadAttempt($attempt->getId()),
            );
            self::fail('Expected SUPER_ADMIN unauthorized for student review DTO');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testSuperAdminCanStillManageReviewPolicy(): void
    {
        $fx = $this->activatedClassroomDelivery('arra2');
        $sa = $this->reloadUser($fx['sa']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        $draft = $this->reviewPolicies()->createDraft(
            $delivery,
            $sa,
            ResultReviewAvailabilityMode::Never,
            null,
            true,
            false,
            false,
            false,
            false,
            'sa_manage',
        );
        $active = $this->reviewPolicies()->activate(
            $this->reloadReviewPolicy($draft->getId()),
            $sa,
            'sa_activate',
        );
        self::assertTrue($active->getStatus()->isActivePolicy());
    }

    public function testDbPromotedSuperAdminOtherUserCannotReadReviewDto(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arra3',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );
        $teacher = $this->reloadUser($fx['teacher']->getId());
        self::assertNotContains(UserRole::SuperAdmin->value, $teacher->getRoles());

        $roles = array_values(array_unique([...$teacher->getRoles(), UserRole::SuperAdmin->value]));
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET global_roles = ? WHERE id = ?',
            [json_encode($roles, \JSON_THROW_ON_ERROR), $teacher->getId()->toBinary()],
        );
        self::assertNotContains(
            UserRole::SuperAdmin->value,
            $teacher->getRoles(),
            'Precondition: managed teacher still lacks SUPER_ADMIN',
        );

        try {
            $this->reviewReader()->readReview($teacher, $this->reloadAttempt($attempt->getId()));
            self::fail('Expected DB-promoted SUPER_ADMIN teacher unauthorized');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testStaleManagedOwnerDeniedWhenMembershipEndedInDb(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arra4',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );
        $actor = $this->reloadUser($fx['student']->getId());
        $managedAttempt = $this->em->find(\App\Entity\AssessmentAttempt::class, $attempt->getId());
        self::assertNotNull($managedAttempt);
        self::assertTrue($actor->getId()->equals($managedAttempt->getUser()->getId()));
        self::assertSame(InstitutionMembershipStatus::Active, $fx['studentMembership']->getStatus());

        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            [InstitutionMembershipStatus::Ended->value, $fx['studentMembership']->getId()->toBinary()],
        );
        self::assertSame(
            InstitutionMembershipStatus::Active,
            $fx['studentMembership']->getStatus(),
            'Precondition: managed membership still Active',
        );
        self::assertTrue($actor->getId()->equals($managedAttempt->getUser()->getId()), 'Precondition: managed attempt still owned');

        try {
            $this->reviewReader()->readReview($actor, $managedAttempt);
            self::fail('Expected unauthorized after membership ended in DB');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testActiveStudentCannotReadOtherStudentAttempt(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arra5',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );
        $other = $this->activeUser('arra5-other@example.com');
        $this->membershipManager()->addMember(
            $this->reloadInstitution($fx['institution']->getId()),
            $this->reloadUser($fx['owner']->getId()),
            $other,
            InstitutionMembershipRole::Student,
            'add_other',
        );

        try {
            $this->reviewReader()->readReview(
                $this->reloadUser($other->getId()),
                $this->reloadAttempt($attempt->getId()),
            );
            self::fail('Expected cross-user unauthorized');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testOtherInstitutionMembershipDoesNotGrantReviewAccess(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arra6',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );
        $student = $this->reloadUser($fx['student']->getId());
        $sa = $this->reloadUser($fx['sa']->getId());
        $otherOwner = $this->activeUser('arra6-owner2@example.com');
        $otherInstitution = $this->institutionCreator()->create(
            $sa,
            $otherOwner,
            'Arra6 Other School',
            InstitutionType::School,
            'platform_setup',
        );
        $this->institutionStatus()->activate($otherInstitution, $sa, 'activate_other');
        $this->membershipManager()->addMember(
            $this->reloadInstitution($otherInstitution->getId()),
            $this->reloadUser($otherOwner->getId()),
            $student,
            InstitutionMembershipRole::Student,
            'add_cross_tenant',
        );

        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            [InstitutionMembershipStatus::Ended->value, $fx['studentMembership']->getId()->toBinary()],
        );
        self::assertSame(
            InstitutionMembershipStatus::Active,
            $fx['studentMembership']->getStatus(),
            'Precondition: managed home membership still Active',
        );

        try {
            $this->reviewReader()->readReview($student, $this->reloadAttempt($attempt->getId()));
            self::fail('Expected deny when home membership ended despite other-tenant membership');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }
        unset($otherInstitution);
    }

    public function testSuspendedStudentMembershipDeniesReview(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arra7',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );
        $student = $this->reloadUser($fx['student']->getId());
        self::assertSame(InstitutionMembershipStatus::Active, $fx['studentMembership']->getStatus());

        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            [InstitutionMembershipStatus::Suspended->value, $fx['studentMembership']->getId()->toBinary()],
        );
        self::assertSame(InstitutionMembershipStatus::Active, $fx['studentMembership']->getStatus());

        try {
            $this->reviewReader()->readReview($student, $this->reloadAttempt($attempt->getId()));
            self::fail('Expected suspended membership unauthorized');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testStaleRevokedRecipientDeniesReview(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arra8',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );
        $student = $this->reloadUser($fx['student']->getId());
        /** @var AssessmentDeliveryRecipient $recipient */
        $recipient = $this->em->find(AssessmentDeliveryRecipient::class, $fx['recipient']->getId());
        self::assertSame(AssessmentDeliveryRecipientStatus::Eligible, $recipient->getStatus());

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            'UPDATE assessment_delivery_recipients
             SET status = ?, revoked_at = ?, revoked_by_id = ?, revocation_reason_code = ?
             WHERE id = ?',
            [
                AssessmentDeliveryRecipientStatus::Revoked->value,
                $now,
                $fx['owner']->getId()->toBinary(),
                'stale_revoke',
                $recipient->getId()->toBinary(),
            ],
        );
        self::assertSame(
            AssessmentDeliveryRecipientStatus::Eligible,
            $recipient->getStatus(),
            'Precondition: managed recipient still Eligible',
        );

        try {
            $this->reviewReader()->readReview($student, $this->reloadAttempt($attempt->getId()));
            self::fail('Expected revoked recipient unauthorized');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testRecipientIdentitySpoofDeniedByDatabase(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arra9',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );
        $other = $this->activeUser('arra9-spoof@example.com');

        try {
            $this->em->getConnection()->executeStatement(
                'UPDATE assessment_delivery_recipients SET user_id = ? WHERE id = ?',
                [$other->getId()->toBinary(), $fx['recipient']->getId()->toBinary()],
            );
            self::fail('Expected recipient identity immutability');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }

        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        self::assertTrue($view->getAttemptId()->equals($attempt->getId()));
    }

    public function testLegitimateStudentOwnerCanReadOwnReviewDto(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arra10',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );
        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        self::assertTrue($view->getAttemptId()->equals($attempt->getId()));
        self::assertTrue($view->isScoreSummaryIncluded());
    }

    public function testAdminModeratorOwnerManagerTeacherStaffDenied(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arra11',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );
        $institution = $this->reloadInstitution($fx['institution']->getId());
        $owner = $this->reloadUser($fx['owner']->getId());

        $manager = $this->activeUser('arra11-mgr@example.com');
        $this->membershipManager()->addMember(
            $institution,
            $owner,
            $manager,
            InstitutionMembershipRole::Manager,
            'add_mgr',
        );
        $staff = $this->activeUser('arra11-staff@example.com');
        $this->membershipManager()->addMember(
            $institution,
            $owner,
            $staff,
            InstitutionMembershipRole::Staff,
            'add_staff',
        );
        $admin = $this->activeUser('arra11-admin@example.com');
        $admin->addGlobalRole(UserRole::Admin);
        $this->users->save($admin);
        $mod = $this->activeUser('arra11-mod@example.com');
        $mod->addGlobalRole(UserRole::Moderator);
        $this->users->save($mod);

        $actors = [
            $this->reloadUser($fx['owner']->getId()),
            $this->reloadUser($manager->getId()),
            $this->reloadUser($fx['teacher']->getId()),
            $this->reloadUser($staff->getId()),
            $this->reloadUser($admin->getId()),
            $this->reloadUser($mod->getId()),
        ];
        foreach ($actors as $actor) {
            try {
                $this->reviewReader()->readReview($actor, $this->reloadAttempt($attempt->getId()));
                self::fail('Expected unauthorized for '.$actor->getEmail());
            } catch (AssessmentResultReviewException $e) {
                self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
            }
        }
    }

    private function reloadInstitution(\Symfony\Component\Uid\Uuid $id): Institution
    {
        $institution = $this->em->find(Institution::class, $id);
        self::assertInstanceOf(Institution::class, $institution);

        return $institution;
    }

    private function sqlState(\Throwable $e): ?string
    {
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof \Doctrine\DBAL\Driver\Exception) {
                return $current->getSQLState();
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssessmentAttemptAnswer;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\AssessmentAttemptFailureReason;
use App\Enum\InstitutionMembershipRole;
use App\Enum\InstitutionStatus;
use App\Enum\InstitutionType;
use App\Enum\UserStatus;
use App\Exception\AssessmentAttemptException;
use App\Tests\Support\AssessmentAttemptTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AttemptAnswerReader must authorize from fresh DB state (HINT_REFRESH), not managed entities.
 */
final class AttemptAnswerReaderAuthorizationTest extends KernelTestCase
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
        if (isset($this->em)) {
            $this->cleanupDeliveryFixtures();
        }
        parent::tearDown();
    }

    public function testUserSuspendedDeniesRead(): void
    {
        [$answer, $student] = $this->createAnswerAndOwner('aar1');
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $student->getId()->toBinary()],
        );
        self::assertSame(UserStatus::Active, $student->getStatus());

        $this->expectReadDenied($answer, $student, AssessmentAttemptFailureReason::UserInactive);
    }

    public function testEmailVerificationClearedDeniesRead(): void
    {
        [$answer, $student] = $this->createAnswerAndOwner('aar2');
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET email_verified_at = NULL WHERE id = ?',
            [$student->getId()->toBinary()],
        );
        self::assertNotNull($student->getEmailVerifiedAt());

        $this->expectReadDenied($answer, $student, AssessmentAttemptFailureReason::EmailNotVerified);
    }

    public function testMembershipEndedDeniesRead(): void
    {
        [$answer, $student, $fx] = $this->createAnswerOwnerAndFixture('aar3');
        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            ['ended', $fx['studentMembership']->getId()->toBinary()],
        );

        $this->expectReadDenied($answer, $student, AssessmentAttemptFailureReason::MembershipInactive);
    }

    public function testMembershipSuspendedDeniesRead(): void
    {
        [$answer, $student, $fx] = $this->createAnswerOwnerAndFixture('aar4');
        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET status = ? WHERE id = ?',
            ['suspended', $fx['studentMembership']->getId()->toBinary()],
        );

        $this->expectReadDenied($answer, $student, AssessmentAttemptFailureReason::MembershipInactive);
    }

    public function testMembershipRoleChangedFromStudentDeniesRead(): void
    {
        [$answer, $student, $fx] = $this->createAnswerOwnerAndFixture('aar5');
        $this->em->getConnection()->executeStatement(
            'UPDATE institution_memberships SET role = ? WHERE id = ?',
            [InstitutionMembershipRole::Teacher->value, $fx['studentMembership']->getId()->toBinary()],
        );

        $this->expectReadDenied($answer, $student, AssessmentAttemptFailureReason::MembershipNotStudent);
    }

    public function testRecipientRevokedDeniesRead(): void
    {
        [$answer, $student, $fx] = $this->createAnswerOwnerAndFixture('aar6');
        $this->em->getConnection()->executeStatement(
            'UPDATE assessment_delivery_recipients
             SET status = ?, revoked_at = UTC_TIMESTAMP(), revoked_by_id = ?, revocation_reason_code = ?
             WHERE id = ?',
            [
                'revoked',
                $fx['owner']->getId()->toBinary(),
                'read_revoke',
                $fx['recipient']->getId()->toBinary(),
            ],
        );

        $this->expectReadDenied($answer, $student, AssessmentAttemptFailureReason::RecipientRevoked);
    }

    public function testInstitutionSuspendedDeniesRead(): void
    {
        [$answer, $student, $fx] = $this->createAnswerOwnerAndFixture('aar7');
        $this->em->getConnection()->executeStatement(
            'UPDATE institutions SET status = ? WHERE id = ?',
            [InstitutionStatus::Suspended->value, $fx['institution']->getId()->toBinary()],
        );

        $this->expectReadDenied($answer, $student, AssessmentAttemptFailureReason::InstitutionInactive);
    }

    public function testCrossUserOtherStudentDeniesRead(): void
    {
        $fx = $this->activatedClassroomDelivery('aar8');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_cross_user',
        );
        $answer = $this->attempts()->saveAnswer(
            $attempt,
            $this->firstAttemptItem($attempt),
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_cross_user',
        );

        $other = $this->activeUser('aar8-other@example.com');
        $this->membershipManager()->addMember(
            $fx['institution'],
            $fx['owner'],
            $other,
            InstitutionMembershipRole::Student,
            'add_other_stu',
        );

        $this->expectReadDenied($answer, $other, AssessmentAttemptFailureReason::Unauthorized);
    }

    public function testCrossTenantDeniesRead(): void
    {
        $fx = $this->activatedClassroomDelivery('aar9');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_xt',
        );
        $answer = $this->attempts()->saveAnswer(
            $attempt,
            $this->firstAttemptItem($attempt),
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_xt',
        );

        $otherOwner = $this->activeUser('aar9-other-owner@example.com');
        $otherInst = $this->institutionCreator()->create(
            $fx['sa'],
            $otherOwner,
            'aar9 Other School',
            InstitutionType::School,
            'platform_setup',
        );
        self::assertInstanceOf(Institution::class, $otherInst);
        $this->institutionStatus()->activate($otherInst, $fx['sa'], 'activate');
        $foreignStudent = $this->activeUser('aar9-foreign@example.com');
        $this->membershipManager()->addMember(
            $otherInst,
            $otherOwner,
            $foreignStudent,
            InstitutionMembershipRole::Student,
            'add_foreign',
        );

        $this->expectReadDenied($answer, $foreignStudent, AssessmentAttemptFailureReason::Unauthorized);
    }

    public function testManagedStaleEntityWithOutOfBandSuspendDeniesRead(): void
    {
        [$answer, $student] = $this->createAnswerAndOwner('aar10');
        self::assertSame(UserStatus::Active, $student->getStatus());
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $student->getId()->toBinary()],
        );
        self::assertSame(UserStatus::Active, $student->getStatus(), 'Precondition: managed entity still Active');

        $this->expectReadDenied($answer, $student, AssessmentAttemptFailureReason::UserInactive);
    }

    public function testDetachAndClearStillDenyCorrectly(): void
    {
        [$answer, $student] = $this->createAnswerAndOwner('aar11');
        $answerId = $answer->getId();
        $studentId = $student->getId();

        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $studentId->toBinary()],
        );

        $this->em->detach($answer);
        $this->em->detach($student);
        $this->em->clear();

        $reloadedAnswer = $this->em->find(AssessmentAttemptAnswer::class, $answerId);
        $reloadedStudent = $this->reloadUser($studentId);
        self::assertInstanceOf(AssessmentAttemptAnswer::class, $reloadedAnswer);

        $this->expectReadDenied(
            $reloadedAnswer,
            $reloadedStudent,
            AssessmentAttemptFailureReason::UserInactive,
        );
    }

    public function testPrivilegedNonOwnersCannotDecryptViaReadForOwner(): void
    {
        $fx = $this->activatedClassroomDelivery('aar12');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_priv',
        );
        $answer = $this->attempts()->saveAnswer(
            $attempt,
            $this->firstAttemptItem($attempt),
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_priv',
        );

        $managerUser = $this->activeUser('aar12-mgr@example.com');
        $this->membershipManager()->addMember(
            $fx['institution'],
            $fx['owner'],
            $managerUser,
            InstitutionMembershipRole::Manager,
            'add_mgr',
        );

        foreach ([
            'owner' => $fx['owner'],
            'teacher' => $fx['teacher'],
            'manager' => $managerUser,
            'sa' => $fx['sa'],
        ] as $label => $caller) {
            try {
                $this->answerReader()->readForOwner($answer, $this->reloadUser($caller->getId()));
                self::fail('Expected privileged non-owner denied: '.$label);
            } catch (AssessmentAttemptException $e) {
                self::assertSame(
                    AssessmentAttemptFailureReason::Unauthorized,
                    $e->getReason(),
                    $label,
                );
            }
        }
    }

    public function testRealOwnerSucceeds(): void
    {
        [$answer, $student] = $this->createAnswerAndOwner('aar13');
        $payload = $this->answerReader()->readForOwner($answer, $student);
        self::assertSame('opt_b', $payload['selectedStableKey'] ?? null);
    }

    /**
     * @return array{0: AssessmentAttemptAnswer, 1: User}
     */
    private function createAnswerAndOwner(string $prefix): array
    {
        [$answer, $student] = $this->createAnswerOwnerAndFixture($prefix);

        return [$answer, $student];
    }

    /**
     * @return array{0: AssessmentAttemptAnswer, 1: User, 2: array<string, mixed>}
     */
    private function createAnswerOwnerAndFixture(string $prefix): array
    {
        $fx = $this->activatedClassroomDelivery($prefix);
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_'.$prefix,
        );
        $answer = $this->attempts()->saveAnswer(
            $attempt,
            $this->firstAttemptItem($attempt),
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_'.$prefix,
        );

        return [$answer, $student, $fx];
    }

    private function expectReadDenied(
        AssessmentAttemptAnswer $answer,
        User $caller,
        AssessmentAttemptFailureReason $reason,
    ): void {
        try {
            $this->answerReader()->readForOwner($answer, $caller);
            self::fail('Expected read denied: '.$reason->value);
        } catch (AssessmentAttemptException $e) {
            self::assertSame($reason, $e->getReason());
        }
    }
}

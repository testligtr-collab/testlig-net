<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssessmentAttemptFailureReason;
use App\Enum\UserStatus;
use App\Exception\AssessmentAttemptException;
use App\Tests\Support\AssessmentAttemptTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Managed entities stay stale after out-of-band DBAL updates; manager must re-check DB.
 */
final class AssessmentAttemptStaleAuthorizationTest extends KernelTestCase
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

    public function testStartSucceedsThenDbalSuspendDeniesStartAndSave(): void
    {
        $fx = $this->activatedClassroomDelivery('aasa1');
        $student = $this->reloadUser($fx['student']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $attempt = $this->attempts()->startAttempt($delivery, $student, 'start_stale');
        $attemptId = $attempt->getId();
        $itemId = $this->firstAttemptItem($attempt)->getId();
        $studentId = $student->getId();
        $deliveryId = $delivery->getId();

        self::assertSame(UserStatus::Active, $student->getStatus());
        $this->em->getConnection()->executeStatement(
            'UPDATE users SET status = ? WHERE id = ?',
            [UserStatus::Suspended->value, $studentId->toBinary()],
        );
        self::assertSame(UserStatus::Active, $student->getStatus());

        try {
            $this->attempts()->startAttempt($delivery, $student, 'start_after_susp');
            self::fail('Expected user inactive on start.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::UserInactive, $e->getReason());
        }
        $this->resetDoctrineDelivery();

        $attempt = $this->reloadAttempt($attemptId);
        $item = $this->em->find(\App\Entity\AssessmentAttemptItem::class, $itemId);
        self::assertInstanceOf(\App\Entity\AssessmentAttemptItem::class, $item);
        $student = $this->reloadUser($studentId);

        try {
            $this->attempts()->saveAnswer(
                $attempt,
                $item,
                $student,
                $this->singleChoicePayload('opt_b'),
                0,
                'save_after_susp',
            );
            self::fail('Expected user inactive on save.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::UserInactive, $e->getReason());
        }
    }

    public function testRecipientRevokeViaDbalDeniesSave(): void
    {
        $fx = $this->activatedClassroomDelivery('aasa2');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_rev',
        );
        $item = $this->firstAttemptItem($attempt);

        $this->em->getConnection()->executeStatement(
            'UPDATE assessment_delivery_recipients
             SET status = ?, revoked_at = ?, revoked_by_id = ?, revocation_reason_code = ?
             WHERE id = ?',
            [
                'revoked',
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                $fx['owner']->getId()->toBinary(),
                'stale_revoke',
                $fx['recipient']->getId()->toBinary(),
            ],
        );

        try {
            $this->attempts()->saveAnswer(
                $attempt,
                $item,
                $student,
                $this->singleChoicePayload('opt_b'),
                0,
                'save_revoked',
            );
            self::fail('Expected recipient revoked.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::RecipientRevoked, $e->getReason());
        }
    }

    public function testDeliveryCloseDeniesNewStart(): void
    {
        $fx = $this->activatedClassroomDelivery('aasa3');
        $student = $this->reloadUser($fx['student']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $this->attempts()->startAttempt($delivery, $student, 'start_close');

        $this->deliveries()->close($this->reloadDelivery($delivery->getId()), $fx['owner'], 'close_stale');

        try {
            $this->attempts()->startAttempt(
                $this->reloadDelivery($delivery->getId()),
                $student,
                'start_after_close',
            );
            self::fail('Expected delivery not active.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::DeliveryNotActive, $e->getReason());
        }
    }
}

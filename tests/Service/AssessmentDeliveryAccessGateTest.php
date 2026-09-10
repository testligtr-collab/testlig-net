<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\User;
use App\Enum\AssessmentDeliveryAccessReason;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\UserStatus;
use App\Tests\Support\AssessmentDeliveryTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssessmentDeliveryAccessGateTest extends KernelTestCase
{
    use AssessmentDeliveryTestFixtures;

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

    public function testAllowedMarksAttemptQuotaPending(): void
    {
        $ctx = $this->publishedDeliveryContext('adag');
        [$opens, $closes] = $this->defaultWindow();
        $delivery = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Classroom,
            $ctx['classroom'],
            null,
            $ctx['owner'],
            $opens,
            $closes,
            2,
            null,
            null,
            'create_ag',
        );
        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_ag');
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);
        $student = $this->users->find($ctx['student']->getId());
        self::assertInstanceOf(User::class, $student);

        $decision = $this->accessGate()->evaluate($delivery->getId(), $student);
        self::assertTrue($decision->eligibleForAttemptCreation);
        self::assertTrue($decision->attemptQuotaMustBeChecked);
        self::assertSame(AssessmentDeliveryAccessReason::Allowed, $decision->reason);
        self::assertSame(2, $decision->configuredMaxAttempts);
        self::assertSame($ctx['publication']->getId()->toRfc4122(), $decision->assessmentPublicationId);
    }

    public function testWindowAndStatusAndRevokeDenials(): void
    {
        $ctx = $this->publishedDeliveryContext('adag2');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $delivery = $this->deliveries()->createDraft(
            $ctx['institution'],
            $ctx['publication'],
            AssessmentDeliveryAudienceType::Classroom,
            $ctx['classroom'],
            null,
            $ctx['owner'],
            $now->modify('+1 day'),
            $now->modify('+7 days'),
            1,
            null,
            null,
            'create_future',
        );
        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_future');
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        $student = $this->users->find($ctx['student']->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);
        self::assertInstanceOf(User::class, $student);

        $decision = $this->accessGate()->evaluate($delivery->getId(), $student);
        self::assertFalse($decision->eligibleForAttemptCreation);
        self::assertSame(AssessmentDeliveryAccessReason::NotOpenYet, $decision->reason);

        $this->deliveries()->close($delivery, $ctx['owner'], 'close_ag');
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);
        $decision = $this->accessGate()->evaluate($delivery->getId(), $student);
        self::assertSame(AssessmentDeliveryAccessReason::DeliveryNotActive, $decision->reason);
        self::assertSame(AssessmentDeliveryStatus::Closed, $delivery->getStatus());
    }

    public function testUserInactiveAndCrossTenantRecipient(): void
    {
        $ctx = $this->publishedDeliveryContext('adag3');
        [$opens, $closes] = $this->defaultWindow();
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
            'create_u',
        );
        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_u');
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);

        $recipientId = $this->em->getConnection()->fetchOne(
            'SELECT id FROM assessment_delivery_recipients WHERE delivery_id = :id LIMIT 1',
            ['id' => $delivery->getId()->toBinary()],
        );
        self::assertIsString($recipientId);
        $recipient = $this->em->find(
            AssessmentDeliveryRecipient::class,
            \Symfony\Component\Uid\Uuid::fromBinary($recipientId),
        );
        self::assertInstanceOf(AssessmentDeliveryRecipient::class, $recipient);
        $this->deliveries()->revokeRecipient($delivery, $recipient, $ctx['owner'], 'revoke_r');

        $student = $this->users->find($ctx['student']->getId());
        self::assertInstanceOf(User::class, $student);
        $decision = $this->accessGate()->evaluate($delivery->getId(), $student);
        self::assertSame(AssessmentDeliveryAccessReason::RecipientRevoked, $decision->reason);

        $other = $this->activeUser('adag3-other@example.com');
        $decision = $this->accessGate()->evaluate($delivery->getId(), $other);
        self::assertSame(AssessmentDeliveryAccessReason::RecipientNotFound, $decision->reason);

        // Reactivate path for inactive user: create new delivery for remaining students after re-enroll scenario
        $this->resetDoctrineDelivery();
        $ctx = $this->publishedDeliveryContext('adag4');
        [$opens, $closes] = $this->defaultWindow();
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
            'create_inactive',
        );
        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_inactive');
        $student = $this->users->find($ctx['student']->getId());
        self::assertInstanceOf(User::class, $student);
        $student->transitionTo(UserStatus::Suspended);
        $this->users->save($student);
        $this->em->flush();
        $decision = $this->accessGate()->evaluate($delivery->getId(), $student);
        self::assertSame(AssessmentDeliveryAccessReason::UserInactive, $decision->reason);
    }

    public function testExpiredWindowDeniesAccess(): void
    {
        $ctx = $this->publishedDeliveryContext('adag5');
        $pastOpen = new \DateTimeImmutable('-3 days', new \DateTimeZone('UTC'));
        $pastClose = new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC'));
        $expiredId = \Symfony\Component\Uid\Uuid::v7();
        $conn = $this->em->getConnection();

        $enrollmentId = $conn->fetchOne(
            'SELECT id FROM classroom_student_enrollments
             WHERE classroom_id = ? AND student_membership_id = ? AND status = ? LIMIT 1',
            [
                $ctx['classroom']->getId()->toBinary(),
                $ctx['studentMembership']->getId()->toBinary(),
                'active',
            ],
        );
        self::assertIsString($enrollmentId);

        // BI requires draft insert; then recipient; then BU draft→active with past window for gate coverage.
        $conn->insert('assessment_deliveries', [
            'id' => $expiredId->toBinary(),
            'institution_id' => $ctx['institution']->getId()->toBinary(),
            'assessment_id' => $ctx['assessment']->getId()->toBinary(),
            'assessment_publication_id' => $ctx['publication']->getId()->toBinary(),
            'publication_number' => $ctx['publication']->getPublicationNumber(),
            'audience_type' => 'classroom',
            'classroom_id' => $ctx['classroom']->getId()->toBinary(),
            'student_membership_id' => null,
            'status' => 'draft',
            'opens_at' => $pastOpen->format('Y-m-d H:i:s'),
            'closes_at' => $pastClose->format('Y-m-d H:i:s'),
            'max_attempts' => 1,
            'title_override' => null,
            'instructions_override' => null,
            'created_by_id' => $ctx['owner']->getId()->toBinary(),
            'activated_by_id' => null,
            'activated_at' => null,
            'closed_by_id' => null,
            'closed_at' => null,
            'cancelled_by_id' => null,
            'cancelled_at' => null,
            'cancellation_reason_code' => null,
            'created_at' => $pastOpen->format('Y-m-d H:i:s'),
            'updated_at' => $pastOpen->format('Y-m-d H:i:s'),
        ]);
        $conn->insert('assessment_delivery_recipients', [
            'id' => \Symfony\Component\Uid\Uuid::v7()->toBinary(),
            'delivery_id' => $expiredId->toBinary(),
            'institution_id' => $ctx['institution']->getId()->toBinary(),
            'student_membership_id' => $ctx['studentMembership']->getId()->toBinary(),
            'user_id' => $ctx['student']->getId()->toBinary(),
            'status' => 'eligible',
            'source_classroom_id' => $ctx['classroom']->getId()->toBinary(),
            'source_enrollment_id' => $enrollmentId,
            'assigned_at' => $pastOpen->format('Y-m-d H:i:s'),
            'revoked_at' => null,
            'revoked_by_id' => null,
            'revocation_reason_code' => null,
        ]);
        $conn->executeStatement(
            'UPDATE assessment_deliveries
             SET status = :status,
                 activated_by_id = :actor,
                 activated_at = :activatedAt,
                 updated_at = :updatedAt
             WHERE id = :id',
            [
                'status' => 'active',
                'actor' => $ctx['owner']->getId()->toBinary(),
                'activatedAt' => $pastOpen->format('Y-m-d H:i:s'),
                'updatedAt' => $pastOpen->format('Y-m-d H:i:s'),
                'id' => $expiredId->toBinary(),
            ],
        );

        $this->em->clear();
        $student = $this->users->find($ctx['student']->getId());
        self::assertInstanceOf(User::class, $student);
        $decision = $this->accessGate()->evaluate($expiredId, $student);
        self::assertFalse($decision->eligibleForAttemptCreation);
        self::assertSame(AssessmentDeliveryAccessReason::Expired, $decision->reason);
    }
}

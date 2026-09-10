<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssessmentDelivery;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\AssessmentDeliveryFailureReason;
use App\Enum\AssessmentDeliveryStatus;
use App\Enum\SecurityAuditAction;
use App\Exception\AssessmentDeliveryException;
use App\Tests\Support\AssessmentDeliveryTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssessmentDeliveryDomainTest extends KernelTestCase
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

    public function testCreateDraftUpdateLifecycleCloseAndCancel(): void
    {
        $ctx = $this->publishedDeliveryContext('addom');
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
            3,
            'Override title',
            'Override instructions',
            'create_d',
        );
        self::assertSame(AssessmentDeliveryStatus::Draft, $delivery->getStatus());
        self::assertSame(3, $delivery->getMaxAttempts());
        self::assertSame('Override title', $delivery->getTitleOverride());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AssessmentDeliveryCreated->value));

        $this->deliveries()->updateDraft(
            $delivery,
            $ctx['owner'],
            $opens,
            $closes->modify('+1 day'),
            5,
            'Updated title',
            null,
            'update_d',
        );
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);
        self::assertSame(5, $delivery->getMaxAttempts());
        self::assertSame('Updated title', $delivery->getTitleOverride());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AssessmentDeliveryDraftUpdated->value));

        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_d');
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);
        self::assertSame(AssessmentDeliveryStatus::Active, $delivery->getStatus());
        self::assertSame(1, $this->events->countByAction(SecurityAuditAction::AssessmentDeliveryActivated->value));
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM assessment_delivery_recipients WHERE delivery_id = :id',
                ['id' => $delivery->getId()->toBinary()],
            ),
        );

        try {
            $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_again');
            self::fail('second activation');
        } catch (AssessmentDeliveryException $e) {
            self::assertSame(AssessmentDeliveryFailureReason::InvalidTransition, $e->getReason());
        }
        $this->resetDoctrineDelivery();
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        $owner = $this->users->find($ctx['owner']->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);
        self::assertInstanceOf(\App\Entity\User::class, $owner);

        $this->deliveries()->close($delivery, $owner, 'close_d');
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);
        self::assertSame(AssessmentDeliveryStatus::Closed, $delivery->getStatus());

        try {
            $this->deliveries()->close($delivery, $owner, 'reopen');
            self::fail('closed cannot reopen');
        } catch (AssessmentDeliveryException $e) {
            self::assertSame(AssessmentDeliveryFailureReason::InvalidTransition, $e->getReason());
        }
    }

    public function testInvalidWindowAndMaxAttempts(): void
    {
        $ctx = $this->publishedDeliveryContext('adwin');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $this->deliveries()->createDraft(
                $ctx['institution'],
                $ctx['publication'],
                AssessmentDeliveryAudienceType::Institution,
                null,
                null,
                $ctx['owner'],
                $now->modify('+2 days'),
                $now->modify('+1 day'),
                1,
                null,
                null,
                'bad_window',
            );
            self::fail('invalid window');
        } catch (AssessmentDeliveryException $e) {
            self::assertSame(AssessmentDeliveryFailureReason::InvalidInput, $e->getReason());
        }

        $this->resetDoctrineDelivery();
        $ctx = $this->publishedDeliveryContext('admax');
        [$opens, $closes] = $this->defaultWindow();
        try {
            $this->deliveries()->createDraft(
                $ctx['institution'],
                $ctx['publication'],
                AssessmentDeliveryAudienceType::Institution,
                null,
                null,
                $ctx['owner'],
                $opens,
                $closes,
                11,
                null,
                null,
                'bad_max',
            );
            self::fail('max attempts');
        } catch (AssessmentDeliveryException $e) {
            self::assertSame(AssessmentDeliveryFailureReason::InvalidInput, $e->getReason());
        }
    }

    public function testSecondActivationRejectedAndStatusStaysActive(): void
    {
        $ctx = $this->publishedDeliveryContext('adact2');
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
            'create_twice',
        );
        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_once');
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);
        self::assertSame(AssessmentDeliveryStatus::Active, $delivery->getStatus());
        $recipientCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_delivery_recipients WHERE delivery_id = ?',
            [$delivery->getId()->toBinary()],
        );
        self::assertGreaterThan(0, $recipientCount);

        try {
            $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_twice');
            self::fail('second activation');
        } catch (AssessmentDeliveryException $e) {
            self::assertSame(AssessmentDeliveryFailureReason::InvalidTransition, $e->getReason());
        }

        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);
        self::assertSame(AssessmentDeliveryStatus::Active, $delivery->getStatus());
        self::assertSame($recipientCount, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_delivery_recipients WHERE delivery_id = ?',
            [$delivery->getId()->toBinary()],
        ));
    }

    public function testCancelFromDraftAndTeacherCannotCreateInstitutionAudience(): void
    {
        $ctx = $this->publishedDeliveryContext('adcan');
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
            'create_c',
        );
        $this->deliveries()->cancel($delivery, $ctx['owner'], 'cancel_d', 'cancelled_by_owner');
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);
        self::assertSame(AssessmentDeliveryStatus::Cancelled, $delivery->getStatus());
        self::assertSame('cancelled_by_owner', $delivery->getCancellationReasonCode());

        try {
            $this->deliveries()->createDraft(
                $ctx['institution'],
                $ctx['publication'],
                AssessmentDeliveryAudienceType::Institution,
                null,
                null,
                $ctx['teacher'],
                $opens,
                $closes,
                1,
                null,
                null,
                'teacher_inst',
            );
            self::fail('teacher institution audience');
        } catch (AssessmentDeliveryException $e) {
            self::assertSame(AssessmentDeliveryFailureReason::Unauthorized, $e->getReason());
        }
    }
}

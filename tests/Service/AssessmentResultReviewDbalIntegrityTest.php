<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ResultReviewAvailabilityMode;
use App\Enum\ResultReviewPolicyStatus;
use App\Tests\Support\AssessmentResultReviewTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\UuidV7;

final class AssessmentResultReviewDbalIntegrityTest extends KernelTestCase
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

    public function testVersionMustBeSequential(): void
    {
        $fx = $this->activatedClassroomDelivery('arrdi1');
        $owner = $this->reloadUser($fx['owner']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $this->reviewPolicies()->createDraft(
            $delivery,
            $owner,
            ResultReviewAvailabilityMode::Never,
            null,
            true,
            false,
            false,
            false,
            false,
            'v1_arrdi1',
        );

        $conn = $this->em->getConnection();
        $id = (new UuidV7())->toBinary();
        $hash = str_repeat('a', 64);
        try {
            $conn->executeStatement(
                'INSERT INTO assessment_result_review_policies (
                    id, institution_id, delivery_id, version, status, availability_mode, scheduled_at,
                    show_score_summary, show_item_outcomes, show_student_answer, show_correct_answer, show_explanation,
                    created_by_id, activated_by_id, created_at, activated_at, superseded_at, updated_at,
                    reason_code, policy_hash, schema_version
                 ) VALUES (
                    ?, ?, ?, 3, ?, ?, NULL,
                    1, 0, 0, 0, 0,
                    ?, NULL, UTC_TIMESTAMP(), NULL, NULL, UTC_TIMESTAMP(),
                    ?, ?, 1
                 )',
                [
                    $id,
                    $delivery->getInstitution()->getId()->toBinary(),
                    $delivery->getId()->toBinary(),
                    ResultReviewPolicyStatus::Draft->value,
                    ResultReviewAvailabilityMode::Never->value,
                    $owner->getId()->toBinary(),
                    'bad_version',
                    $hash,
                ],
            );
            self::fail('Expected version MAX+1 rejection');
        } catch (\Throwable $e) {
            self::assertStringContainsString('review_policy version must be MAX+1', $e->getMessage());
        }
    }

    public function testActiveContentImmutable(): void
    {
        $fx = $this->activatedClassroomDelivery('arrdi2');
        $owner = $this->reloadUser($fx['owner']->getId());
        $policy = $this->activateFullReviewPolicy(
            $this->reloadDelivery($fx['delivery']->getId()),
            $owner,
            'arrdi2',
        );

        try {
            $this->em->getConnection()->executeStatement(
                'UPDATE assessment_result_review_policies SET show_score_summary = 0 WHERE id = ?',
                [$policy->getId()->toBinary()],
            );
            self::fail('Expected immutability rejection');
        } catch (\Throwable $e) {
            self::assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function testActiveOrSupersededCannotDelete(): void
    {
        $fx = $this->activatedClassroomDelivery('arrdi3');
        $owner = $this->reloadUser($fx['owner']->getId());
        $policy = $this->activateFullReviewPolicy(
            $this->reloadDelivery($fx['delivery']->getId()),
            $owner,
            'arrdi3',
        );

        try {
            $this->em->getConnection()->executeStatement(
                'DELETE FROM assessment_result_review_policies WHERE id = ?',
                [$policy->getId()->toBinary()],
            );
            self::fail('Expected delete rejection');
        } catch (\Throwable $e) {
            self::assertStringContainsString('cannot be deleted', $e->getMessage());
        }
    }

    public function testNeverFlagsCheckConstraint(): void
    {
        $fx = $this->activatedClassroomDelivery('arrdi4');
        $owner = $this->reloadUser($fx['owner']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $conn = $this->em->getConnection();
        $id = (new UuidV7())->toBinary();
        $hash = str_repeat('b', 64);

        try {
            $conn->executeStatement(
                'INSERT INTO assessment_result_review_policies (
                    id, institution_id, delivery_id, version, status, availability_mode, scheduled_at,
                    show_score_summary, show_item_outcomes, show_student_answer, show_correct_answer, show_explanation,
                    created_by_id, activated_by_id, created_at, activated_at, superseded_at, updated_at,
                    reason_code, policy_hash, schema_version
                 ) VALUES (
                    ?, ?, ?, 1, ?, ?, NULL,
                    1, 1, 0, 0, 0,
                    ?, NULL, UTC_TIMESTAMP(), NULL, NULL, UTC_TIMESTAMP(),
                    ?, ?, 1
                 )',
                [
                    $id,
                    $delivery->getInstitution()->getId()->toBinary(),
                    $delivery->getId()->toBinary(),
                    ResultReviewPolicyStatus::Draft->value,
                    ResultReviewAvailabilityMode::Never->value,
                    $owner->getId()->toBinary(),
                    'bad_never_flags',
                    $hash,
                ],
            );
            self::fail('Expected CHECK rejection');
        } catch (\Throwable $e) {
            self::assertNotEmpty($e->getMessage());
        }
    }

    public function testCannotDetachActiveGuard(): void
    {
        $fx = $this->activatedClassroomDelivery('arrdi5');
        $owner = $this->reloadUser($fx['owner']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $this->activateFullReviewPolicy($delivery, $owner, 'arrdi5');

        try {
            $this->em->getConnection()->executeStatement(
                'DELETE FROM assessment_result_active_review_policy_guards WHERE delivery_id = ?',
                [$delivery->getId()->toBinary()],
            );
            self::fail('Expected guard detach rejection');
        } catch (\Throwable $e) {
            self::assertStringContainsString('cannot detach active review policy guard', $e->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssessmentAttemptStatus;
use App\Tests\Support\AssessmentAttemptTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AssessmentAttemptDbalIntegrityTest extends KernelTestCase
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

    public function testIdentityMutationDenied(): void
    {
        $fx = $this->activatedClassroomDelivery('aadi1');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_id',
        );
        $conn = $this->em->getConnection();

        try {
            $conn->executeStatement(
                'UPDATE assessment_attempts SET user_id = ? WHERE id = ?',
                [$fx['owner']->getId()->toBinary(), $attempt->getId()->toBinary()],
            );
            self::fail('Expected identity mutation denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testDuplicateAttemptNumberDenied(): void
    {
        $fx = $this->activatedClassroomDelivery('aadi2');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_dupn',
        );
        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $expires = (new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        try {
            $conn->insert('assessment_attempts', [
                'id' => Uuid::v7()->toBinary(),
                'delivery_id' => $fx['delivery']->getId()->toBinary(),
                'recipient_id' => $fx['recipient']->getId()->toBinary(),
                'institution_id' => $fx['institution']->getId()->toBinary(),
                'student_membership_id' => $fx['studentMembership']->getId()->toBinary(),
                'user_id' => $fx['student']->getId()->toBinary(),
                'assessment_id' => $fx['assessment']->getId()->toBinary(),
                'assessment_publication_id' => $fx['publication']->getId()->toBinary(),
                'publication_number' => $fx['publication']->getPublicationNumber(),
                'attempt_number' => $attempt->getAttemptNumber(),
                'status' => AssessmentAttemptStatus::InProgress->value,
                'started_at' => $now,
                'expires_at' => $expires,
                'submitted_at' => null,
                'expired_at' => null,
                'cancelled_at' => null,
                'cancelled_by_id' => null,
                'cancellation_reason_code' => null,
                'last_activity_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Expected duplicate attempt_number denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testSecondActiveGuardInsertDenied(): void
    {
        $fx = $this->activatedClassroomDelivery('aadi3');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_guard',
        );
        $conn = $this->em->getConnection();

        try {
            $conn->insert('assessment_attempt_active_guards', [
                'recipient_id' => $fx['recipient']->getId()->toBinary(),
                'attempt_id' => $attempt->getId()->toBinary(),
                'delivery_id' => $fx['delivery']->getId()->toBinary(),
            ]);
            self::fail('Expected second active guard denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testTerminalAttemptAnswerInsertDenied(): void
    {
        $fx = $this->activatedClassroomDelivery('aadi4');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_term',
        );
        $item = $this->firstAttemptItem($attempt);
        $this->attempts()->submit($attempt, $student, 'submit_term');

        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        try {
            $conn->insert('assessment_attempt_answers', [
                'id' => Uuid::v7()->toBinary(),
                'attempt_id' => $attempt->getId()->toBinary(),
                'attempt_item_id' => $item->getId()->toBinary(),
                'answer_ciphertext' => random_bytes(32),
                'answer_nonce' => random_bytes(24),
                'encryption_version' => 1,
                'client_revision' => 1,
                'answered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Expected terminal answer insert denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testAttemptTriggersHaveNoBypassOrSessionVariables(): void
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT TRIGGER_NAME, ACTION_STATEMENT
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND TRIGGER_NAME LIKE 'trg_assessment_attempt%'
             ORDER BY TRIGGER_NAME",
        );
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            $body = (string) $row['ACTION_STATEMENT'];
            self::assertStringNotContainsStringIgnoringCase('@testlig', $body);
            self::assertStringNotContainsStringIgnoringCase('bypass', $body);
            self::assertStringNotContainsStringIgnoringCase('FOREIGN_KEY_CHECKS', $body);
            self::assertStringNotContainsStringIgnoringCase('test-only', $body);
            self::assertStringNotContainsStringIgnoringCase('@', $body);
        }
    }

    public function testLifecycleCheckConstraintExists(): void
    {
        $hasCheck = (int) $this->em->getConnection()->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.check_constraints
            WHERE constraint_schema = DATABASE()
              AND constraint_name = 'chk_aa_lifecycle_fields'
            SQL);
        self::assertGreaterThan(0, $hasCheck);
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

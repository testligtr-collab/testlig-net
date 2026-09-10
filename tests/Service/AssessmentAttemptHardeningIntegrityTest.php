<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssessmentDelivery;
use App\Entity\AssessmentDeliveryRecipient;
use App\Entity\AssessmentPublication;
use App\Entity\InstitutionMembership;
use App\Entity\User;
use App\Enum\AssessmentAttemptStatus;
use App\Enum\AssessmentDeliveryAudienceType;
use App\Enum\InstitutionMembershipRole;
use App\Tests\Support\AssessmentAttemptTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * Stage 2.11 merge-pre: DB integrity hardening for attempts, guards, items, answers.
 */
final class AssessmentAttemptHardeningIntegrityTest extends KernelTestCase
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

    // -------------------------------------------------------------------------
    // A) Active scope / guards
    // -------------------------------------------------------------------------

    public function testInProgressInsertAutoCreatesGuard(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg1');
        $attemptId = Uuid::v7();
        $this->em->getConnection()->insert(
            'assessment_attempts',
            $this->validAttemptInsertRow($fx, $attemptId, 1),
        );

        $scope = $this->em->getConnection()->fetchOne(
            'SELECT active_recipient_scope_id FROM assessment_attempts WHERE id = ?',
            [$attemptId->toBinary()],
        );
        self::assertNotFalse($scope);
        self::assertNotNull($scope);

        $guard = $this->em->getConnection()->fetchAssociative(
            'SELECT recipient_id, attempt_id, delivery_id FROM assessment_attempt_active_guards WHERE attempt_id = ?',
            [$attemptId->toBinary()],
        );
        self::assertIsArray($guard);
        self::assertSame($fx['recipient']->getId()->toBinary(), $this->blobToString($guard['recipient_id']));
        self::assertSame($attemptId->toBinary(), $this->blobToString($guard['attempt_id']));
        self::assertSame($fx['delivery']->getId()->toBinary(), $this->blobToString($guard['delivery_id']));
    }

    public function testSecondInProgressSameRecipientRejected(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg2');
        $conn = $this->em->getConnection();
        $conn->insert('assessment_attempts', $this->validAttemptInsertRow($fx, Uuid::v7(), 1));

        try {
            $conn->insert('assessment_attempts', $this->validAttemptInsertRow($fx, Uuid::v7(), 2));
            self::fail('Expected second in_progress for same recipient denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertContains($this->sqlState($e), ['45000', '23000']);
        }
    }

    public function testSecondInProgressDifferentRecipientAccepted(): void
    {
        $fx = $this->activatedClassroomDeliveryWithSecondStudent('aahg3');
        $conn = $this->em->getConnection();
        $conn->insert('assessment_attempts', $this->validAttemptInsertRow($fx, Uuid::v7(), 1));

        $id2 = Uuid::v7();
        $conn->insert(
            'assessment_attempts',
            $this->validAttemptInsertRow($fx, $id2, 1, $fx['recipient2'], $fx['student2'], $fx['studentMembership2']),
        );

        $guardCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_attempt_active_guards');
        self::assertSame(2, $guardCount);
        self::assertNotFalse($conn->fetchOne(
            'SELECT 1 FROM assessment_attempt_active_guards WHERE attempt_id = ?',
            [$id2->toBinary()],
        ));
    }

    public function testTerminalClearsScopeAndGuardThenNewAttemptAccepted(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg4', 2);
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_term_clear',
        );
        $this->attempts()->submit($attempt, $student, 'submit_term_clear');

        $conn = $this->em->getConnection();
        $scope = $conn->fetchOne(
            'SELECT active_recipient_scope_id FROM assessment_attempts WHERE id = ?',
            [$attempt->getId()->toBinary()],
        );
        self::assertNull($scope);
        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM assessment_attempt_active_guards WHERE recipient_id = ?',
            [$fx['recipient']->getId()->toBinary()],
        ));

        $newId = Uuid::v7();
        $conn->insert('assessment_attempts', $this->validAttemptInsertRow($fx, $newId, 2));
        self::assertNotFalse($conn->fetchOne(
            'SELECT 1 FROM assessment_attempt_active_guards WHERE attempt_id = ?',
            [$newId->toBinary()],
        ));
    }

    public function testDirectDeleteActiveGuardWhileInProgressRejected(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg5');
        $student = $this->reloadUser($fx['student']->getId());
        $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_guard_del',
        );

        try {
            $this->em->getConnection()->executeStatement(
                'DELETE FROM assessment_attempt_active_guards WHERE recipient_id = ?',
                [$fx['recipient']->getId()->toBinary()],
            );
            self::fail('Expected active guard delete denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testGuardInsertForTerminalAttemptRejected(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg6');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_term_guard',
        );
        $this->attempts()->submit($attempt, $student, 'submit_term_guard');

        try {
            $this->em->getConnection()->insert('assessment_attempt_active_guards', [
                'recipient_id' => $fx['recipient']->getId()->toBinary(),
                'attempt_id' => $attempt->getId()->toBinary(),
                'delivery_id' => $fx['delivery']->getId()->toBinary(),
            ]);
            self::fail('Expected guard insert for terminal attempt denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testExpireAndCancelLeaveNoStaleGuard(): void
    {
        $mock = new MockClock();
        Clock::set($mock);

        $fx = $this->activatedClassroomDelivery('aahg7', 3);
        $student = $this->reloadUser($fx['student']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        $expired = $this->attempts()->startAttempt($delivery, $student, 'start_exp');
        $mock->modify('+2 hours');
        $this->attempts()->expire($expired, 'system_expire');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_attempt_active_guards WHERE attempt_id = ?',
            [$expired->getId()->toBinary()],
        ));
        self::assertNull($this->em->getConnection()->fetchOne(
            'SELECT active_recipient_scope_id FROM assessment_attempts WHERE id = ?',
            [$expired->getId()->toBinary()],
        ));

        // Reset clock for cancel path (cancel does not require expiry).
        Clock::set(new NativeClock());

        $cancelled = $this->attempts()->startAttempt(
            $this->reloadDelivery($delivery->getId()),
            $student,
            'start_can',
        );
        $this->attempts()->cancel(
            $cancelled,
            $this->reloadUser($fx['owner']->getId()),
            'cancel_own',
            'owner_cancel',
        );
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_attempt_active_guards WHERE attempt_id = ?',
            [$cancelled->getId()->toBinary()],
        ));
    }

    public function testRollbackOfAttemptInsertLeavesNoOrphanGuard(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg8');
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $conn->insert('assessment_attempts', $this->validAttemptInsertRow($fx, Uuid::v7(), 1));
            self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_attempt_active_guards'));
            throw new \RuntimeException('force rollback');
        } catch (\RuntimeException) {
            $conn->rollBack();
        }

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_attempts'));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_attempt_active_guards'));
    }

    // -------------------------------------------------------------------------
    // B) Cross-assessment item graph
    // -------------------------------------------------------------------------

    public function testAttemptItemSectionFromOtherRevisionRejected(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg9');
        [$attemptId, $own, $other] = $this->attemptAndTwoGraphs($fx, 'aahg9b');

        try {
            $this->insertAttemptItemRow($attemptId, $own, [
                'assessment_section_id' => $other['section_id'],
                'assessment_revision_id' => $own['revision_id'],
            ]);
            self::fail('Expected section from other revision denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertContains($this->sqlState($e), ['45000', '23000']);
        }
    }

    public function testAttemptItemFromOtherAssessmentRejected(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg10');
        [$attemptId, $own, $other] = $this->attemptAndTwoGraphs($fx, 'aahg10b');

        try {
            $this->insertAttemptItemRow($attemptId, $own, [
                'assessment_item_id' => $other['item_id'],
                'assessment_section_id' => $other['section_id'],
                'assessment_revision_id' => $own['revision_id'],
                'question_id' => $other['question_id'],
                'question_revision_id' => $other['question_revision_id'],
                'public_content_hash' => $other['public_content_hash'],
            ]);
            self::fail('Expected item from other assessment denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertContains($this->sqlState($e), ['45000', '23000']);
        }
    }

    public function testAttemptItemSectionItemMismatchRejected(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg11');
        [$attemptId, $own, $other] = $this->attemptAndTwoGraphs($fx, 'aahg11b');

        try {
            $this->insertAttemptItemRow($attemptId, $own, [
                'assessment_section_id' => $own['section_id'],
                'assessment_item_id' => $other['item_id'],
            ]);
            self::fail('Expected section/item mismatch denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertContains($this->sqlState($e), ['45000', '23000']);
        }
    }

    public function testAttemptItemQuestionRevisionSpoofRejected(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg12');
        [$attemptId, $own, $other] = $this->attemptAndTwoGraphs($fx, 'aahg12b');

        try {
            $this->insertAttemptItemRow($attemptId, $own, [
                'question_id' => $other['question_id'],
                'question_revision_id' => $other['question_revision_id'],
                'public_content_hash' => $other['public_content_hash'],
            ]);
            self::fail('Expected question revision spoof denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testValidGraphAttemptItemAccepted(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg13');
        $attemptId = Uuid::v7();
        $this->em->getConnection()->insert(
            'assessment_attempts',
            $this->validAttemptInsertRow($fx, $attemptId, 1),
        );
        $graph = $this->loadPublicationGraph($fx['publication']);
        $this->insertAttemptItemRow($attemptId, $graph);

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_attempt_items WHERE attempt_id = ?',
            [$attemptId->toBinary()],
        ));
    }

    public function testRollbackLeavesNoHalfAttemptItem(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg14');
        $conn = $this->em->getConnection();
        $attemptId = Uuid::v7();
        $graph = $this->loadPublicationGraph($fx['publication']);

        $conn->beginTransaction();
        try {
            $conn->insert('assessment_attempts', $this->validAttemptInsertRow($fx, $attemptId, 1));
            $this->insertAttemptItemRow($attemptId, $graph);
            self::assertSame(1, (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM assessment_attempt_items WHERE attempt_id = ?',
                [$attemptId->toBinary()],
            ));
            throw new \RuntimeException('force rollback');
        } catch (\RuntimeException) {
            $conn->rollBack();
        }

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_attempts'));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_attempt_items'));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM assessment_attempt_active_guards'));
    }

    // -------------------------------------------------------------------------
    // C) Expired answer mutation
    // -------------------------------------------------------------------------

    public function testAnswerInsertAcceptedWhileUnexpiredInProgress(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg15');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_ans_ok',
        );
        $item = $this->firstAttemptItem($attempt);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->em->getConnection()->insert('assessment_attempt_answers', [
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

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM assessment_attempt_answers WHERE attempt_id = ?',
            [$attempt->getId()->toBinary()],
        ));
    }

    public function testAnswerInsertRejectedWhenExpiresAtInPast(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg16');
        $attemptId = Uuid::v7();
        $row = $this->validAttemptInsertRow($fx, $attemptId, 1);
        $started = (new \DateTimeImmutable('-30 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $expired = (new \DateTimeImmutable('-1 second', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $row['started_at'] = $started;
        $row['expires_at'] = $expired;
        $row['last_activity_at'] = $started;
        $row['created_at'] = $started;
        $row['updated_at'] = $started;
        $this->em->getConnection()->insert('assessment_attempts', $row);

        $graph = $this->loadPublicationGraph($fx['publication']);
        $itemId = Uuid::v7();
        $this->insertAttemptItemRow($attemptId, $graph, ['id' => $itemId->toBinary()]);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        try {
            $this->em->getConnection()->insert('assessment_attempt_answers', [
                'id' => Uuid::v7()->toBinary(),
                'attempt_id' => $attemptId->toBinary(),
                'attempt_item_id' => $itemId->toBinary(),
                'answer_ciphertext' => random_bytes(32),
                'answer_nonce' => random_bytes(24),
                'encryption_version' => 1,
                'client_revision' => 1,
                'answered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('Expected answer insert on past expires_at denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testAnswerInsertRejectedWhenExpiresAtEqualsUtcTimestamp(): void
    {
        // expires_at is immutable on UPDATE; insert with expires_at = UTC_TIMESTAMP() (boundary).
        $fx = $this->activatedClassroomDelivery('aahg17');
        $conn = $this->em->getConnection();
        $idBin = Uuid::v7()->toBinary();
        $conn->executeStatement(
            'INSERT INTO assessment_attempts (
                id, delivery_id, recipient_id, institution_id, student_membership_id, user_id,
                assessment_id, assessment_publication_id, assessment_revision_id, publication_number,
                attempt_number, status, started_at, expires_at, submitted_at, expired_at, cancelled_at,
                cancelled_by_id, cancellation_reason_code, last_activity_at, created_at, updated_at
             ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                1, ?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE), UTC_TIMESTAMP(),
                NULL, NULL, NULL, NULL, NULL,
                DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE),
                DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE),
                DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
             )',
            [
                $idBin,
                $fx['delivery']->getId()->toBinary(),
                $fx['recipient']->getId()->toBinary(),
                $fx['institution']->getId()->toBinary(),
                $fx['studentMembership']->getId()->toBinary(),
                $fx['student']->getId()->toBinary(),
                $fx['assessment']->getId()->toBinary(),
                $fx['publication']->getId()->toBinary(),
                $fx['publication']->getAssessmentRevision()->getId()->toBinary(),
                $fx['publication']->getPublicationNumber(),
                AssessmentAttemptStatus::InProgress->value,
            ],
        );

        $attemptId = Uuid::fromBinary($idBin);
        $graph = $this->loadPublicationGraph($fx['publication']);
        $itemId = Uuid::v7();
        $this->insertAttemptItemRow($attemptId, $graph, ['id' => $itemId->toBinary()]);

        try {
            $conn->executeStatement(
                'INSERT INTO assessment_attempt_answers (
                    id, attempt_id, attempt_item_id, answer_ciphertext, answer_nonce,
                    encryption_version, client_revision, answered_at, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [
                    Uuid::v7()->toBinary(),
                    $idBin,
                    $itemId->toBinary(),
                    random_bytes(32),
                    random_bytes(24),
                ],
            );
            self::fail('Expected answer insert at exact expires_at denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testAnswerUpdateRejectedAfterSleepPastExpiresAtPreservesCiphertext(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg18');
        $conn = $this->em->getConnection();
        $attemptIdBin = Uuid::v7()->toBinary();
        $conn->executeStatement(
            'INSERT INTO assessment_attempts (
                id, delivery_id, recipient_id, institution_id, student_membership_id, user_id,
                assessment_id, assessment_publication_id, assessment_revision_id, publication_number,
                attempt_number, status, started_at, expires_at, submitted_at, expired_at, cancelled_at,
                cancelled_by_id, cancellation_reason_code, last_activity_at, created_at, updated_at
             ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 2 SECOND,
                NULL, NULL, NULL, NULL, NULL,
                UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                $attemptIdBin,
                $fx['delivery']->getId()->toBinary(),
                $fx['recipient']->getId()->toBinary(),
                $fx['institution']->getId()->toBinary(),
                $fx['studentMembership']->getId()->toBinary(),
                $fx['student']->getId()->toBinary(),
                $fx['assessment']->getId()->toBinary(),
                $fx['publication']->getId()->toBinary(),
                $fx['publication']->getAssessmentRevision()->getId()->toBinary(),
                $fx['publication']->getPublicationNumber(),
                AssessmentAttemptStatus::InProgress->value,
            ],
        );

        $attemptId = Uuid::fromBinary($attemptIdBin);
        $graph = $this->loadPublicationGraph($fx['publication']);
        $itemId = Uuid::v7();
        $this->insertAttemptItemRow($attemptId, $graph, ['id' => $itemId->toBinary()]);

        $answerId = Uuid::v7();
        $cipher = random_bytes(32);
        $nonce = random_bytes(24);
        $conn->executeStatement(
            'INSERT INTO assessment_attempt_answers (
                id, attempt_id, attempt_item_id, answer_ciphertext, answer_nonce,
                encryption_version, client_revision, answered_at, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$answerId->toBinary(), $attemptIdBin, $itemId->toBinary(), $cipher, $nonce],
        );

        sleep(3);

        try {
            $conn->executeStatement(
                'UPDATE assessment_attempt_answers
                    SET answer_ciphertext = ?, answer_nonce = ?, client_revision = 2,
                        answered_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                  WHERE id = ?',
                [random_bytes(32), random_bytes(24), $answerId->toBinary()],
            );
            self::fail('Expected answer update after expiry denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }

        $preserved = $conn->fetchAssociative(
            'SELECT answer_ciphertext, answer_nonce, client_revision FROM assessment_attempt_answers WHERE id = ?',
            [$answerId->toBinary()],
        );
        self::assertIsArray($preserved);
        self::assertSame($cipher, $this->blobToString($preserved['answer_ciphertext']));
        self::assertSame($nonce, $this->blobToString($preserved['answer_nonce']));
        self::assertSame(1, (int) $preserved['client_revision']);
    }

    // -------------------------------------------------------------------------
    // D) Answer revision / nonce / ciphertext
    // -------------------------------------------------------------------------

    public function testAnswerUpdateRevisionAndNonceRules(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg19');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_rev_rules',
        );
        $item = $this->firstAttemptItem($attempt);
        $answer = $this->attempts()->saveAnswer(
            $attempt,
            $item,
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_rev_rules',
        );

        $conn = $this->em->getConnection();
        $answerId = $answer->getId()->toBinary();
        $before = $conn->fetchAssociative(
            'SELECT answer_ciphertext, answer_nonce, client_revision, answered_at, updated_at FROM assessment_attempt_answers WHERE id = ?',
            [$answerId],
        );
        self::assertIsArray($before);
        $oldCipher = $this->blobToString($before['answer_ciphertext']);
        $oldNonce = $this->blobToString($before['answer_nonce']);
        $oldAnsweredAt = (string) $before['answered_at'];
        // Derive from DB value so PHP/MariaDB timezone skew cannot make "later" go backward.
        $later = (new \DateTimeImmutable($oldAnsweredAt))->modify('+1 second')->format('Y-m-d H:i:s');

        $this->expectDbal45000(static function () use ($conn, $answerId, $later): void {
            $conn->update('assessment_attempt_answers', [
                'client_revision' => 3,
                'answer_ciphertext' => random_bytes(32),
                'answer_nonce' => random_bytes(24),
                'answered_at' => $later,
                'updated_at' => $later,
            ], ['id' => $answerId]);
        }, 'skip revision +2');

        $this->expectDbal45000(static function () use ($conn, $answerId, $later): void {
            $conn->update('assessment_attempt_answers', [
                'client_revision' => 1,
                'answer_ciphertext' => random_bytes(32),
                'answer_nonce' => random_bytes(24),
                'answered_at' => $later,
                'updated_at' => $later,
            ], ['id' => $answerId]);
        }, 'same revision');

        $this->expectDbal45000(static function () use ($conn, $answerId, $oldNonce, $later): void {
            $conn->update('assessment_attempt_answers', [
                'client_revision' => 2,
                'answer_ciphertext' => random_bytes(32),
                'answer_nonce' => $oldNonce,
                'answered_at' => $later,
                'updated_at' => $later,
            ], ['id' => $answerId]);
        }, 'nonce reuse');

        $this->expectDbal45000(static function () use ($conn, $answerId, $later): void {
            $conn->update('assessment_attempt_answers', [
                'client_revision' => 2,
                'answer_ciphertext' => random_bytes(32),
                'answer_nonce' => random_bytes(23),
                'answered_at' => $later,
                'updated_at' => $later,
            ], ['id' => $answerId]);
        }, '23-byte nonce');

        $this->expectDbal45000(static function () use ($conn, $answerId, $later): void {
            $conn->update('assessment_attempt_answers', [
                'client_revision' => 2,
                'answer_ciphertext' => random_bytes(32),
                'answer_nonce' => random_bytes(25),
                'answered_at' => $later,
                'updated_at' => $later,
            ], ['id' => $answerId]);
        }, '25-byte nonce');

        $this->expectDbal45000(static function () use ($conn, $answerId, $later): void {
            $conn->update('assessment_attempt_answers', [
                'client_revision' => 2,
                'answer_ciphertext' => random_bytes(8),
                'answer_nonce' => random_bytes(24),
                'answered_at' => $later,
                'updated_at' => $later,
            ], ['id' => $answerId]);
        }, 'short ciphertext');

        $this->expectDbal45000(static function () use ($conn, $answerId, $later): void {
            $conn->update('assessment_attempt_answers', [
                'client_revision' => 2,
                'answer_ciphertext' => '',
                'answer_nonce' => random_bytes(24),
                'answered_at' => $later,
                'updated_at' => $later,
            ], ['id' => $answerId]);
        }, 'empty ciphertext');

        $this->expectDbal45000(static function () use ($conn, $answerId, $oldAnsweredAt): void {
            $conn->update('assessment_attempt_answers', [
                'client_revision' => 2,
                'answer_ciphertext' => random_bytes(32),
                'answer_nonce' => random_bytes(24),
                'answered_at' => (new \DateTimeImmutable($oldAnsweredAt, new \DateTimeZone('UTC')))
                    ->modify('-1 second')
                    ->format('Y-m-d H:i:s'),
                'updated_at' => $oldAnsweredAt,
            ], ['id' => $answerId]);
        }, 'answered_at backward');

        $afterReject = $conn->fetchAssociative(
            'SELECT answer_ciphertext, answer_nonce, client_revision FROM assessment_attempt_answers WHERE id = ?',
            [$answerId],
        );
        self::assertIsArray($afterReject);
        self::assertSame($oldCipher, $this->blobToString($afterReject['answer_ciphertext']));
        self::assertSame($oldNonce, $this->blobToString($afterReject['answer_nonce']));
        self::assertSame(1, (int) $afterReject['client_revision']);

        $newCipher = random_bytes(32);
        $newNonce = random_bytes(24);
        $conn->update('assessment_attempt_answers', [
            'client_revision' => 2,
            'answer_ciphertext' => $newCipher,
            'answer_nonce' => $newNonce,
            'answered_at' => $later,
            'updated_at' => $later,
        ], ['id' => $answerId]);

        $ok = $conn->fetchAssociative(
            'SELECT answer_ciphertext, answer_nonce, client_revision FROM assessment_attempt_answers WHERE id = ?',
            [$answerId],
        );
        self::assertIsArray($ok);
        self::assertSame($newCipher, $this->blobToString($ok['answer_ciphertext']));
        self::assertSame($newNonce, $this->blobToString($ok['answer_nonce']));
        self::assertSame(2, (int) $ok['client_revision']);
    }

    public function testAnswerInsertRevisionAndNonceRules(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg20');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_ins_rules',
        );
        $item = $this->firstAttemptItem($attempt);
        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $base = [
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
        ];

        $this->expectDbal45000(static function () use ($conn, $base): void {
            $row = $base;
            $row['id'] = Uuid::v7()->toBinary();
            $row['client_revision'] = 2;
            $conn->insert('assessment_attempt_answers', $row);
        }, 'insert client_revision != 1');

        $this->expectDbal45000(static function () use ($conn, $base): void {
            $row = $base;
            $row['id'] = Uuid::v7()->toBinary();
            $row['answer_nonce'] = random_bytes(16);
            $conn->insert('assessment_attempt_answers', $row);
        }, 'insert short nonce');
    }

    // -------------------------------------------------------------------------
    // F) Attempt INSERT scope
    // -------------------------------------------------------------------------

    public function testAttemptInsertIneligibleRecipientRejected(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg21');
        $this->em->getConnection()->executeStatement(
            'UPDATE assessment_delivery_recipients
             SET status = ?, revoked_at = UTC_TIMESTAMP(), revoked_by_id = ?, revocation_reason_code = ?
             WHERE id = ?',
            [
                'revoked',
                $fx['owner']->getId()->toBinary(),
                'scope_rev',
                $fx['recipient']->getId()->toBinary(),
            ],
        );

        try {
            $this->em->getConnection()->insert(
                'assessment_attempts',
                $this->validAttemptInsertRow($fx, Uuid::v7(), 1),
            );
            self::fail('Expected ineligible recipient insert denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testAttemptInsertInactiveDeliveryRejected(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg22');
        $this->deliveries()->close(
            $this->reloadDelivery($fx['delivery']->getId()),
            $fx['owner'],
            'close_scope',
        );

        try {
            $this->em->getConnection()->insert(
                'assessment_attempts',
                $this->validAttemptInsertRow($fx, Uuid::v7(), 1),
            );
            self::fail('Expected inactive delivery insert denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testAttemptInsertExpiresBeforeStartedRejected(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg23');
        $row = $this->validAttemptInsertRow($fx, Uuid::v7(), 1);
        $row['started_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $row['expires_at'] = (new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        try {
            $this->em->getConnection()->insert('assessment_attempts', $row);
            self::fail('Expected expires_at before started_at denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testAttemptInsertTerminalStatusRejected(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg24');
        $row = $this->validAttemptInsertRow($fx, Uuid::v7(), 1);
        $row['status'] = AssessmentAttemptStatus::Submitted->value;
        $row['submitted_at'] = $row['started_at'];

        try {
            $this->em->getConnection()->insert('assessment_attempts', $row);
            self::fail('Expected terminal status insert denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    public function testAttemptInsertMaxAttemptsExceededRejected(): void
    {
        $fx = $this->activatedClassroomDelivery('aahg25', 1);
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_max',
        );
        $this->attempts()->submit($attempt, $student, 'submit_max');

        try {
            $this->em->getConnection()->insert(
                'assessment_attempts',
                $this->validAttemptInsertRow($fx, Uuid::v7(), 2),
            );
            self::fail('Expected max_attempts exceeded insert denied.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e));
        }
    }

    /**
     * @param array{
     *     owner: User,
     *     sa: User,
     *     reviewer: User,
     *     institution: \App\Entity\Institution,
     *     classroom: \App\Entity\Classroom,
     *     teacher: User,
     *     teacherMembership: InstitutionMembership,
     *     student: User,
     *     studentMembership: InstitutionMembership,
     *     assessment: \App\Entity\Assessment,
     *     publication: AssessmentPublication,
     *     delivery: AssessmentDelivery,
     *     recipient: AssessmentDeliveryRecipient
     * } $fx
     *
     * @return array<string, mixed>
     */
    private function validAttemptInsertRow(
        array $fx,
        Uuid $attemptId,
        int $attemptNumber,
        ?AssessmentDeliveryRecipient $recipient = null,
        ?User $student = null,
        ?InstitutionMembership $membership = null,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        $recipient ??= $fx['recipient'];
        $student ??= $fx['student'];
        $membership ??= $fx['studentMembership'];
        $startedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt ??= $startedAt->modify('+1 hour');
        $now = $startedAt->format('Y-m-d H:i:s');

        return [
            'id' => $attemptId->toBinary(),
            'delivery_id' => $fx['delivery']->getId()->toBinary(),
            'recipient_id' => $recipient->getId()->toBinary(),
            'institution_id' => $fx['institution']->getId()->toBinary(),
            'student_membership_id' => $membership->getId()->toBinary(),
            'user_id' => $student->getId()->toBinary(),
            'assessment_id' => $fx['assessment']->getId()->toBinary(),
            'assessment_publication_id' => $fx['publication']->getId()->toBinary(),
            'assessment_revision_id' => $fx['publication']->getAssessmentRevision()->getId()->toBinary(),
            'publication_number' => $fx['publication']->getPublicationNumber(),
            'attempt_number' => $attemptNumber,
            'status' => AssessmentAttemptStatus::InProgress->value,
            'started_at' => $now,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'submitted_at' => null,
            'expired_at' => null,
            'cancelled_at' => null,
            'cancelled_by_id' => null,
            'cancellation_reason_code' => null,
            'last_activity_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array{
     *     owner: User,
     *     sa: User,
     *     reviewer: User,
     *     institution: \App\Entity\Institution,
     *     classroom: \App\Entity\Classroom,
     *     teacher: User,
     *     teacherMembership: InstitutionMembership,
     *     student: User,
     *     studentMembership: InstitutionMembership,
     *     student2: User,
     *     studentMembership2: InstitutionMembership,
     *     assessment: \App\Entity\Assessment,
     *     publication: AssessmentPublication,
     *     delivery: AssessmentDelivery,
     *     recipient: AssessmentDeliveryRecipient,
     *     recipient2: AssessmentDeliveryRecipient
     * }
     */
    private function activatedClassroomDeliveryWithSecondStudent(string $prefix, int $maxAttempts = 2): array
    {
        $ctx = $this->publishedDeliveryContext($prefix);
        $student2 = $this->activeUser($prefix.'-student2@example.com');
        $membership2 = $this->membershipManager()->addMember(
            $ctx['institution'],
            $ctx['owner'],
            $student2,
            InstitutionMembershipRole::Student,
            'add_student2',
        );
        $this->enrollmentManager()->enroll($ctx['classroom'], $ctx['owner'], $membership2, 'enroll_s2');

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
            $maxAttempts,
            null,
            null,
            'create_att2_'.$prefix,
        );
        $this->deliveries()->activate($delivery, $ctx['owner'], 'activate_att2_'.$prefix);
        $delivery = $this->em->find(AssessmentDelivery::class, $delivery->getId());
        self::assertInstanceOf(AssessmentDelivery::class, $delivery);

        $recipient = $this->em->getRepository(AssessmentDeliveryRecipient::class)->findOneBy([
            'delivery' => $delivery,
            'user' => $ctx['student'],
        ]);
        $recipient2 = $this->em->getRepository(AssessmentDeliveryRecipient::class)->findOneBy([
            'delivery' => $delivery,
            'user' => $student2,
        ]);
        self::assertInstanceOf(AssessmentDeliveryRecipient::class, $recipient);
        self::assertInstanceOf(AssessmentDeliveryRecipient::class, $recipient2);

        return $ctx + [
            'student2' => $student2,
            'studentMembership2' => $membership2,
            'delivery' => $delivery,
            'recipient' => $recipient,
            'recipient2' => $recipient2,
        ];
    }

    /**
     * @param array{
     *     owner: User,
     *     sa: User,
     *     reviewer: User,
     *     institution: \App\Entity\Institution,
     *     classroom: \App\Entity\Classroom,
     *     teacher: User,
     *     teacherMembership: InstitutionMembership,
     *     student: User,
     *     studentMembership: InstitutionMembership,
     *     assessment: \App\Entity\Assessment,
     *     publication: AssessmentPublication,
     *     delivery: AssessmentDelivery,
     *     recipient: AssessmentDeliveryRecipient
     * } $fx
     *
     * @return array{0: Uuid, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function attemptAndTwoGraphs(array $fx, string $otherSuffix): array
    {
        $attemptId = Uuid::v7();
        $this->em->getConnection()->insert(
            'assessment_attempts',
            $this->validAttemptInsertRow($fx, $attemptId, 1),
        );
        $own = $this->loadPublicationGraph($fx['publication']);
        [$otherAssessment, $otherPublication] = $this->publishPlatformAssessment(
            $this->reloadUser($fx['sa']->getId()),
            $this->reloadUser($fx['reviewer']->getId()),
            $otherSuffix,
        );
        unset($otherAssessment);
        $other = $this->loadPublicationGraph($otherPublication);

        return [$attemptId, $own, $other];
    }

    /**
     * @return array{
     *     revision_id: string,
     *     section_id: string,
     *     item_id: string,
     *     question_id: string,
     *     question_revision_id: string,
     *     section_position: int,
     *     item_position: int,
     *     points: string,
     *     penalty_points: string,
     *     required: int,
     *     public_content_hash: string,
     *     option_order_json: string
     * }
     */
    private function loadPublicationGraph(AssessmentPublication $publication): array
    {
        $revisionId = $publication->getAssessmentRevision()->getId()->toBinary();
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT s.id AS section_id, s.position AS section_position,
                    i.id AS item_id, i.position AS item_position,
                    i.question_id, i.question_revision_id, i.points, i.penalty_points, i.required,
                    qr.content_hash AS public_content_hash
               FROM assessment_sections s
               INNER JOIN assessment_items i ON i.section_id = s.id
               INNER JOIN question_revisions qr ON qr.id = i.question_revision_id
              WHERE s.revision_id = ?
              ORDER BY s.position ASC, i.position ASC
              LIMIT 1',
            [$revisionId],
        );
        self::assertIsArray($row);

        return [
            'revision_id' => $revisionId,
            'section_id' => $this->blobToString($row['section_id']),
            'item_id' => $this->blobToString($row['item_id']),
            'question_id' => $this->blobToString($row['question_id']),
            'question_revision_id' => $this->blobToString($row['question_revision_id']),
            'section_position' => (int) $row['section_position'],
            'item_position' => (int) $row['item_position'],
            'points' => (string) $row['points'],
            'penalty_points' => (string) $row['penalty_points'],
            'required' => (int) $row['required'],
            'public_content_hash' => (string) $row['public_content_hash'],
            'option_order_json' => json_encode(['opt_a', 'opt_b'], \JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $graph
     * @param array<string, mixed> $overrides
     */
    private function insertAttemptItemRow(Uuid $attemptId, array $graph, array $overrides = []): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $row = array_merge([
            'id' => Uuid::v7()->toBinary(),
            'attempt_id' => $attemptId->toBinary(),
            'assessment_revision_id' => $graph['revision_id'],
            'assessment_section_id' => $graph['section_id'],
            'assessment_item_id' => $graph['item_id'],
            'question_id' => $graph['question_id'],
            'question_revision_id' => $graph['question_revision_id'],
            'section_position' => $graph['section_position'],
            'item_position' => $graph['item_position'],
            'presentation_position' => 1,
            'option_order_json' => $graph['option_order_json'],
            'required' => $graph['required'],
            'points' => $graph['points'],
            'penalty_points' => $graph['penalty_points'],
            'public_content_hash' => $graph['public_content_hash'],
            'created_at' => $now,
        ], $overrides);

        $this->em->getConnection()->insert('assessment_attempt_items', $row);
    }

    private function expectDbal45000(callable $callback, string $label): void
    {
        try {
            $callback();
            self::fail('Expected DBAL failure: '.$label);
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertSame('45000', $this->sqlState($e), $label.': '.$e->getMessage());
        }
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

    private function blobToString(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_resource($value)) {
            $contents = stream_get_contents($value);
            self::assertNotFalse($contents);

            return $contents;
        }

        self::fail('Unexpected blob type.');
    }
}

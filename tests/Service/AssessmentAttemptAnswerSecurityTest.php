<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssessmentAttemptAnswer;
use App\Enum\AssessmentAttemptFailureReason;
use App\Enum\QuestionType;
use App\Exception\AssessmentAttemptException;
use App\Tests\Support\AssessmentAttemptTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

final class AssessmentAttemptAnswerSecurityTest extends KernelTestCase
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

    public function testValidSaveDecryptsCanonicalPayload(): void
    {
        $fx = $this->activatedClassroomDelivery('aas1');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_ans',
        );
        $item = $this->firstAttemptItem($attempt);

        $answer = $this->attempts()->saveAnswer(
            $attempt,
            $item,
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_ok',
        );
        self::assertSame(1, $answer->getClientRevision());

        $payload = $this->answerReader()->readForOwner($answer, $student);
        self::assertSame(1, $payload['version']);
        self::assertSame(QuestionType::SingleChoice->value, $payload['answerType']);
        self::assertSame('opt_b', $payload['selectedStableKey']);
    }

    public function testInvalidStableKeyDenied(): void
    {
        $fx = $this->activatedClassroomDelivery('aas2');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_badkey',
        );
        $item = $this->firstAttemptItem($attempt);

        try {
            $this->attempts()->saveAnswer(
                $attempt,
                $item,
                $student,
                $this->singleChoicePayload('opt_zzz'),
                0,
                'save_bad',
            );
            self::fail('Expected answer invalid.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::AnswerInvalid, $e->getReason());
        }
    }

    public function testDuplicateMultipleKeysDenied(): void
    {
        $fx = $this->activatedClassroomDelivery('aas3');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_dup',
        );
        $item = $this->firstAttemptItem($attempt);
        $revisionId = $item->getQuestionRevision()->getId();

        try {
            $this->answerValidator()->validateAndNormalize(
                QuestionType::MultipleChoice,
                $revisionId,
                [
                    'version' => 1,
                    'answerType' => QuestionType::MultipleChoice->value,
                    'selectedStableKeys' => ['opt_b', 'opt_b'],
                ],
            );
            self::fail('Expected duplicate keys denied.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::AnswerInvalid, $e->getReason());
        }
    }

    public function testStaleExpectedVersionDenied(): void
    {
        $fx = $this->activatedClassroomDelivery('aas4');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_stale',
        );
        $item = $this->firstAttemptItem($attempt);
        $this->attempts()->saveAnswer(
            $attempt,
            $item,
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_v1',
        );

        try {
            $this->attempts()->saveAnswer(
                $this->reloadAttempt($attempt->getId()),
                $item,
                $student,
                $this->singleChoicePayload('opt_a'),
                0,
                'save_stale',
            );
            self::fail('Expected stale answer version.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::StaleAnswerVersion, $e->getReason());
        }
    }

    public function testNewNonceOnUpdateAndPlaintextAbsentFromDb(): void
    {
        $fx = $this->activatedClassroomDelivery('aas5');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_nonce',
        );
        $item = $this->firstAttemptItem($attempt);

        $first = $this->attempts()->saveAnswer(
            $attempt,
            $item,
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_n1',
        );
        $nonce1 = $first->getAnswerNonce();

        $second = $this->attempts()->saveAnswer(
            $this->reloadAttempt($attempt->getId()),
            $item,
            $student,
            $this->singleChoicePayload('opt_a'),
            1,
            'save_n2',
        );
        self::assertSame(2, $second->getClientRevision());
        self::assertNotSame($nonce1, $second->getAnswerNonce());

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT answer_ciphertext, answer_nonce FROM assessment_attempt_answers WHERE id = ?',
            [$second->getId()->toBinary()],
        );
        self::assertIsArray($row);
        $cipher = $this->blobToString($row['answer_ciphertext']);
        self::assertStringNotContainsString('opt_a', $cipher);
        self::assertStringNotContainsString('opt_b', $cipher);
        self::assertStringNotContainsString('selectedStableKey', $cipher);
        self::assertStringNotContainsString('single_choice', $cipher);
    }

    public function testTamperedCiphertextFailsIntegrity(): void
    {
        $fx = $this->activatedClassroomDelivery('aas6');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_tamp',
        );
        $item = $this->firstAttemptItem($attempt);
        $answer = $this->attempts()->saveAnswer(
            $attempt,
            $item,
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_tamp',
        );

        $tampered = $answer->getAnswerCiphertext();
        $tampered[0] = "\0" === $tampered[0] ? "\1" : "\0";
        $conn = $this->em->getConnection();
        $answeredAt = (string) $conn->fetchOne(
            'SELECT answered_at FROM assessment_attempt_answers WHERE id = ?',
            [$answer->getId()->toBinary()],
        );
        $later = (new \DateTimeImmutable($answeredAt))->modify('+1 second')->format('Y-m-d H:i:s');
        // BU trigger requires revision+1 and a fresh nonce; ciphertext is still adversarially corrupted.
        $conn->update('assessment_attempt_answers', [
            'answer_ciphertext' => $tampered,
            'answer_nonce' => random_bytes(24),
            'client_revision' => $answer->getClientRevision() + 1,
            'answered_at' => $later,
            'updated_at' => $later,
        ], ['id' => $answer->getId()->toBinary()]);
        $this->em->clear();

        $reloaded = $this->em->find(AssessmentAttemptAnswer::class, $answer->getId());
        self::assertInstanceOf(AssessmentAttemptAnswer::class, $reloaded);

        try {
            $this->answerReader()->readForOwner($reloaded, $this->reloadUser($student->getId()));
            self::fail('Expected answer integrity failed.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::AnswerIntegrityFailed, $e->getReason());
        }
    }

    public function testCrossAttemptItemDenied(): void
    {
        $fx = $this->activatedClassroomDelivery('aas7', 2);
        $student = $this->reloadUser($fx['student']->getId());
        $delivery = $this->reloadDelivery($fx['delivery']->getId());

        $first = $this->attempts()->startAttempt($delivery, $student, 'start_x1');
        $firstItem = $this->firstAttemptItem($first);
        $this->attempts()->submit($first, $student, 'submit_x1');

        $second = $this->attempts()->startAttempt($delivery, $student, 'start_x2');
        try {
            $this->attempts()->saveAnswer(
                $second,
                $firstItem,
                $student,
                $this->singleChoicePayload('opt_b'),
                0,
                'save_cross',
            );
            self::fail('Expected item not found.');
        } catch (AssessmentAttemptException $e) {
            self::assertSame(AssessmentAttemptFailureReason::ItemNotFound, $e->getReason());
        }
    }

    public function testSerializerIgnoresCiphertextFields(): void
    {
        if (!static::getContainer()->has(SerializerInterface::class)) {
            self::markTestSkipped('Serializer not available.');
        }

        $fx = $this->activatedClassroomDelivery('aas8');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->attempts()->startAttempt(
            $this->reloadDelivery($fx['delivery']->getId()),
            $student,
            'start_ser',
        );
        $item = $this->firstAttemptItem($attempt);
        $answer = $this->attempts()->saveAnswer(
            $attempt,
            $item,
            $student,
            $this->singleChoicePayload('opt_b'),
            0,
            'save_ser',
        );

        /** @var SerializerInterface $serializer */
        $serializer = static::getContainer()->get(SerializerInterface::class);
        $json = $serializer->serialize($answer, 'json');
        self::assertStringNotContainsString('opt_b', $json);
        self::assertStringNotContainsString('selectedStableKey', $json);
        self::assertStringNotContainsStringIgnoringCase('ciphertext', $json);
        self::assertStringNotContainsStringIgnoringCase('nonce', $json);
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

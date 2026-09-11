<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ResultReviewAvailabilityMode;
use App\Tests\Support\AssessmentResultReviewTestFixtures;
use App\Tests\Support\JsonKeyTree;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentResultReviewSecurityLeakTest extends KernelTestCase
{
    use AssessmentResultReviewTestFixtures;

    private const FORBIDDEN_KEYS = [
        'answerPayload',
        'answer_payload',
        'answerCiphertext',
        'answer_ciphertext',
        'answerNonce',
        'answer_nonce',
        'ciphertext',
        'nonce',
        'correctStableKey',
        'correctStableKeys',
        'acceptedAnswers',
        'answerIntegrityHmac',
        'answer_integrity_hmac',
        'hmac',
        'policyHash',
        'policy_hash',
        'secret',
        'password',
        'token',
        'encryptionVersion',
        'encryption_version',
        'QUESTION_ANSWER_INTEGRITY_KEY',
        'ATTEMPT_ANSWER_ENCRYPTION_KEY',
        'email',
        'normalizedEmail',
        'ip',
        'userAgent',
    ];

    private const SCORE_SUMMARY_KEYS = [
        'finalPoints',
        'maximumPoints',
        'percentage',
        'correctCount',
        'incorrectCount',
        'unansweredCount',
    ];

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

    public function testReviewDtoNeverLeaksSecrets(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy('arrsl1');
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        Clock::set(new MockClock($delivery->getClosesAt()->modify('+1 hour')));

        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        $tree = JsonKeyTree::collectKeys($view->toArray());
        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            self::assertNotContains(
                $forbidden,
                $tree,
                \sprintf('Forbidden key %s leaked in StudentResultReviewView', $forbidden),
            );
        }

        $serialized = json_encode($view->toArray(), \JSON_THROW_ON_ERROR);
        foreach (['correctStableKey', 'answerIntegrityHmac', 'ciphertext', 'nonce'] as $needle) {
            self::assertStringNotContainsString($needle, $serialized);
        }
    }

    public function testExceptionMessagesOmitPayloads(): void
    {
        [$attempt, , $fx] = $this->submitAndScoreClassroomAttempt('arrsl2');
        $owner = $this->reloadUser($fx['owner']->getId());
        $latestRun = $this->scoringRuns()->findLatestForAttempt($attempt->getId());
        self::assertNotNull($latestRun);
        $this->releases()->release(
            $this->reloadScoringRun($latestRun->getId()),
            $owner,
            'release_arrsl2',
        );

        try {
            $this->reviewReader()->readReview(
                $this->reloadUser($fx['student']->getId()),
                $this->reloadAttempt($attempt->getId()),
            );
            self::fail('Expected review_policy_not_active');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            self::assertStringNotContainsString('opt_b', $msg);
            self::assertStringNotContainsString('ciphertext', $msg);
            self::assertStringNotContainsString('hmac', strtolower($msg));
        }
    }

    public function testScoreSummaryKeysOmittedWhenDisabled(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arrsl3',
            showScoreSummary: false,
            showItemOutcomes: true,
            showStudentAnswer: false,
            showCorrectAnswer: false,
            showExplanation: false,
        );

        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        self::assertFalse($view->isScoreSummaryIncluded());
        $payload = $view->toArray();
        self::assertArrayHasKey('scoreSummaryIncluded', $payload);
        self::assertFalse($payload['scoreSummaryIncluded']);
        foreach (self::SCORE_SUMMARY_KEYS as $key) {
            self::assertArrayNotHasKey($key, $payload);
        }
        self::assertNotContains('studentAnswer', JsonKeyTree::collectKeys($payload));
    }

    public function testStudentAnswerKeyOmittedWhenFlagFalse(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arrsl4',
            showStudentAnswer: false,
            showCorrectAnswer: false,
            showExplanation: false,
        );
        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        $tree = JsonKeyTree::collectKeys($view->toArray());
        self::assertNotContains('studentAnswer', $tree);
        self::assertNotContains('correctAnswer', $tree);
        self::assertNotContains('explanation', $tree);
        self::assertContains('outcome', $tree);
    }

    public function testCorrectAnswerAndExplanationKeysOmittedBeforeClosesAt(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy('arrsl5');
        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        $tree = JsonKeyTree::collectKeys($view->toArray());
        self::assertContains('studentAnswer', $tree);
        self::assertNotContains('correctAnswer', $tree);
        self::assertNotContains('explanation', $tree);
    }

    public function testCorrectAnswerAndExplanationKeysPresentAfterClosesAt(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy('arrsl6');
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        Clock::set(new MockClock($delivery->getClosesAt()->modify('+1 hour')));

        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        $payload = $view->toArray();
        $tree = JsonKeyTree::collectKeys($payload);
        self::assertContains('correctAnswer', $tree);
        self::assertContains('explanation', $tree);
        self::assertContains('studentAnswer', $tree);
        self::assertArrayHasKey('items', $payload);
        self::assertNotEmpty($payload['items']);
        self::assertArrayHasKey('correctAnswer', $payload['items'][0]);
        self::assertIsArray($payload['items'][0]['correctAnswer']);
        self::assertArrayHasKey('questionType', $payload['items'][0]['correctAnswer']);
    }

    public function testCancelledDeliveryOmitsSensitiveKeys(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy('arrsl7');
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $this->deliveries()->cancel(
            $delivery,
            $this->reloadUser($fx['owner']->getId()),
            'cancel_arrsl7',
            'cancelled_for_dto_keys',
        );
        Clock::set(new MockClock($delivery->getClosesAt()->modify('+1 day')));

        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        $tree = JsonKeyTree::collectKeys($view->toArray());
        self::assertNotContains('correctAnswer', $tree);
        self::assertNotContains('explanation', $tree);
    }

    public function testFiveQuestionTypesPresentationOmitsRawAnswerKeyNames(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy('arrsl8');
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        Clock::set(new MockClock($delivery->getClosesAt()->modify('+2 hours')));

        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        $tree = JsonKeyTree::collectKeys($view->toArray());
        foreach (['correctStableKey', 'correctStableKeys', 'acceptedAnswers', 'answerPayload'] as $forbidden) {
            self::assertNotContains($forbidden, $tree);
        }
        self::assertContains('stableKey', $tree);
    }

    public function testNeverModeOmitsItemSensitiveKeys(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arrsl9',
            ResultReviewAvailabilityMode::Never,
            showScoreSummary: true,
            showItemOutcomes: false,
            showStudentAnswer: false,
            showCorrectAnswer: false,
            showExplanation: false,
        );
        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        $payload = $view->toArray();
        self::assertSame([], $payload['items']);
        foreach (self::SCORE_SUMMARY_KEYS as $key) {
            self::assertArrayHasKey($key, $payload);
        }
        $tree = JsonKeyTree::collectKeys($payload);
        self::assertNotContains('studentAnswer', $tree);
        self::assertNotContains('correctAnswer', $tree);
        self::assertNotContains('explanation', $tree);
    }
}

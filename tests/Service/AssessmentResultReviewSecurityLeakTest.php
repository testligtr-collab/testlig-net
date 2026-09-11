<?php

declare(strict_types=1);

namespace App\Tests\Service;

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
}

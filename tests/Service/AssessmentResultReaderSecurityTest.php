<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssessmentScoringFailureReason;
use App\Exception\AssessmentScoringException;
use App\Tests\Support\AssessmentScoringTestFixtures;
use App\Tests\Support\JsonKeyTree;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentResultReaderSecurityTest extends KernelTestCase
{
    use AssessmentScoringTestFixtures;

    private const FORBIDDEN_RESULT_KEYS = [
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
        'tolerance',
        'answerIntegrityHmac',
        'answer_integrity_hmac',
        'hmac',
        'secret',
        'password',
        'token',
        'encryptionVersion',
        'encryption_version',
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

    public function testStudentSeesOnlyOwnActiveRelease(): void
    {
        [$attemptA, $runA, $fxA] = $this->submitAndScoreClassroomAttempt('arrs1a');
        $this->releases()->release(
            $this->reloadScoringRun($runA->getId()),
            $this->reloadUser($fxA['owner']->getId()),
            'release_a',
        );

        [$attemptB, $runB, $fxB] = $this->submitAndScoreClassroomAttempt('arrs1b');
        $this->releases()->release(
            $this->reloadScoringRun($runB->getId()),
            $this->reloadUser($fxB['owner']->getId()),
            'release_b',
        );

        $viewA = $this->resultReader()->readReleasedResult(
            $this->reloadUser($fxA['student']->getId()),
            $this->reloadAttempt($attemptA->getId()),
        );
        self::assertTrue($viewA->getAttemptId()->equals($attemptA->getId()));

        try {
            $this->resultReader()->readReleasedResult(
                $this->reloadUser($fxA['student']->getId()),
                $this->reloadAttempt($attemptB->getId()),
            );
            self::fail('Expected cross-student result denied.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::Unauthorized, $e->getReason());
        }
    }

    public function testUnpublishedRegradeNotLeaked(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('arrs2');
        $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'release_v1',
        );
        $before = $this->resultReader()->readReleasedResult(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        self::assertSame(1, $before->getReleaseNumber());
        self::assertSame('2.50', $before->getFinalPoints());

        $regrade = $this->scoring()->regradeAttempt(
            $this->reloadAttempt($attempt->getId()),
            null,
            'regrade_unpublished',
        );
        self::assertSame(2, $regrade->getRunNumber());

        $after = $this->resultReader()->readReleasedResult(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        self::assertSame(1, $after->getReleaseNumber());
        self::assertSame($before->getFinalPoints(), $after->getFinalPoints());
        self::assertNotSame($regrade->getId()->toRfc4122(), $after->getAttemptId()->toRfc4122());
    }

    public function testDtoKeyTreeOmitsSecrets(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('arrs3');
        $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'release_dto',
        );
        $view = $this->resultReader()->readReleasedResult(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        $keys = JsonKeyTree::collectKeys($view->toArray());
        foreach (self::FORBIDDEN_RESULT_KEYS as $forbidden) {
            self::assertNotContains($forbidden, $keys);
        }

        $encoded = json_encode($view->toArray(), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsStringIgnoringCase('ciphertext', $encoded);
        self::assertStringNotContainsStringIgnoringCase('hmac', $encoded);
        self::assertStringNotContainsString('correctStableKey', $encoded);
        self::assertStringNotContainsString('opt_b', $encoded);
    }

    public function testWithdrawnResultNotReadable(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('arrs4');
        $release = $this->releases()->release(
            $this->reloadScoringRun($run->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'release_w',
        );
        $this->releases()->withdraw(
            $this->reloadRelease($release->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'withdraw_w',
        );
        try {
            $this->resultReader()->readReleasedResult(
                $this->reloadUser($fx['student']->getId()),
                $this->reloadAttempt($attempt->getId()),
            );
            self::fail('Expected withdrawn result not released.');
        } catch (AssessmentScoringException $e) {
            self::assertSame(AssessmentScoringFailureReason::ResultNotReleased, $e->getReason());
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssessmentResultReviewFailureReason;
use App\Enum\QuestionType;
use App\Enum\ResultReviewAvailabilityMode;
use App\Exception\AssessmentResultReviewException;
use App\Tests\Support\AssessmentResultReviewTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentResultReviewReaderTest extends KernelTestCase
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

    public function testNoActivePolicyDeniesReviewButScoreReaderWorks(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('arrr1');
        $owner = $this->reloadUser($fx['owner']->getId());
        $this->releases()->release($this->reloadScoringRun($run->getId()), $owner, 'release_arrr1');
        $student = $this->reloadUser($fx['student']->getId());
        $attempt = $this->reloadAttempt($attempt->getId());

        $score = $this->resultReader()->readReleasedResult($student, $attempt);
        self::assertSame($attempt->getId()->toRfc4122(), $score->getAttemptId()->toRfc4122());

        try {
            $this->reviewReader()->readReview($student, $attempt);
            self::fail('Expected review_policy_not_active');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::ReviewPolicyNotActive, $e->getReason());
        }
    }

    public function testNeverModeScoreSummaryOnly(): void
    {
        [$attempt, , $fx] = $this->submitAndScoreClassroomAttempt('arrr2');
        $owner = $this->reloadUser($fx['owner']->getId());
        $latestRun = $this->scoringRuns()->findLatestForAttempt($attempt->getId());
        self::assertNotNull($latestRun);
        $this->releases()->release(
            $this->reloadScoringRun($latestRun->getId()),
            $owner,
            'release_arrr2',
        );
        $draft = $this->reviewPolicies()->createDraft(
            $this->reloadDelivery($fx['delivery']->getId()),
            $owner,
            ResultReviewAvailabilityMode::Never,
            null,
            true,
            false,
            false,
            false,
            false,
            'never_arrr2',
        );
        $this->reviewPolicies()->activate($this->reloadReviewPolicy($draft->getId()), $owner, 'act_never_arrr2');

        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        self::assertTrue($view->isScoreSummaryIncluded());
        self::assertNotNull($view->getFinalPoints());
        self::assertSame([], $view->getItems());
    }

    public function testCorrectAnswerDeniedBeforeClosesAt(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy('arrr3');
        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        self::assertNotEmpty($view->getItems());
        $item = $view->getItems()[0];
        self::assertNotNull($item->getStudentAnswer());
        self::assertNull($item->getCorrectAnswer());
        self::assertNull($item->getExplanation());
        self::assertSame('correct', $item->getOutcome());
    }

    public function testCorrectAnswerAllowedAfterClosesAt(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy('arrr4');
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $mock = new MockClock($delivery->getClosesAt()->modify('+1 minute'));
        Clock::set($mock);

        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        $item = $view->getItems()[0];
        self::assertNotNull($item->getCorrectAnswer());
        self::assertSame(QuestionType::SingleChoice, $item->getCorrectAnswer()->getQuestionType());
        self::assertSame('opt_b', $item->getCorrectAnswer()->getStableKey());
        $payload = $view->toArray();
        self::assertArrayNotHasKey('correctStableKey', $payload['items'][0]['correctAnswer'] ?? []);
        self::assertSame('opt_b', $payload['items'][0]['correctAnswer']['stableKey']);
    }

    public function testScheduledAfterCloseUsesMaxOfScheduledAndCloses(): void
    {
        [$attempt, $run, $fx] = $this->submitAndScoreClassroomAttempt('arrr5');
        $owner = $this->reloadUser($fx['owner']->getId());
        $this->releases()->release($this->reloadScoringRun($run->getId()), $owner, 'release_arrr5');
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $scheduled = $delivery->getClosesAt()->modify('+2 days');
        $draft = $this->reviewPolicies()->createDraft(
            $delivery,
            $owner,
            ResultReviewAvailabilityMode::ScheduledAfterClose,
            $scheduled,
            true,
            true,
            true,
            true,
            true,
            'sched_arrr5',
        );
        $this->reviewPolicies()->activate($this->reloadReviewPolicy($draft->getId()), $owner, 'act_sched_arrr5');

        Clock::set(new MockClock($delivery->getClosesAt()->modify('+1 hour')));
        $before = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        self::assertNull($before->getItems()[0]->getCorrectAnswer());

        Clock::set(new MockClock($scheduled->modify('+1 minute')));
        $after = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        self::assertNotNull($after->getItems()[0]->getCorrectAnswer());
    }

    public function testWithdrawnReleaseDeniesReview(): void
    {
        [$attempt, $run, $fx] = $this->submitScoreReleaseWithPolicy(
            'arrr6',
            showCorrectAnswer: false,
            showExplanation: false,
        );
        $release = $this->resultReleases()->findActiveReleasedForAttempt($attempt->getId());
        self::assertNotNull($release);
        $this->releases()->withdraw(
            $release,
            $this->reloadUser($fx['owner']->getId()),
            'withdraw_arrr6',
        );

        try {
            $this->reviewReader()->readReview(
                $this->reloadUser($fx['student']->getId()),
                $this->reloadAttempt($attempt->getId()),
            );
            self::fail('Expected result_not_released');
        } catch (AssessmentResultReviewException $e) {
            self::assertContains($e->getReason(), [
                AssessmentResultReviewFailureReason::ResultNotReleased,
                AssessmentResultReviewFailureReason::ResultWithdrawn,
            ]);
        }
    }

    public function testRegradeDoesNotChangeReviewUntilNewRelease(): void
    {
        [$attempt, $run, $fx] = $this->submitScoreReleaseWithPolicy(
            'arrr7',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );
        $before = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        $releaseNumber = $before->getReleaseNumber();

        $this->scoring()->regradeAttempt(
            $this->reloadAttempt($attempt->getId()),
            $this->reloadUser($fx['owner']->getId()),
            'regrade_arrr7',
        );
        $after = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        self::assertSame($releaseNumber, $after->getReleaseNumber());
        self::assertSame($before->getFinalPoints(), $after->getFinalPoints());
        unset($run);
    }

    public function testPresentationMatrixForFiveQuestionTypes(): void
    {
        $cases = [
            QuestionType::SingleChoice->value => [
                'payload' => ['correctStableKey' => 'opt_a'],
                'assert' => static function ($presentation): void {
                    self::assertSame('opt_a', $presentation->getStableKey());
                },
            ],
            QuestionType::MultipleChoice->value => [
                'payload' => ['correctStableKeys' => ['a', 'c']],
                'assert' => static function ($presentation): void {
                    self::assertSame(['a', 'c'], $presentation->getStableKeys());
                },
            ],
            QuestionType::TrueFalse->value => [
                'payload' => ['correct' => true],
                'assert' => static function ($presentation): void {
                    self::assertTrue($presentation->getBooleanValue());
                },
            ],
            QuestionType::Numeric->value => [
                'payload' => ['value' => '3.14', 'tolerance' => '0.01'],
                'assert' => static function ($presentation): void {
                    self::assertSame('3.14', $presentation->getNumericValue());
                    self::assertSame('0.01', $presentation->getTolerance());
                },
            ],
            QuestionType::ShortAnswer->value => [
                'payload' => ['acceptedAnswers' => ['paris', 'Paris']],
                'assert' => static function ($presentation): void {
                    self::assertSame(['paris', 'Paris'], $presentation->getAcceptedTexts());
                },
            ],
        ];

        foreach ($cases as $type => $case) {
            $presentation = match (QuestionType::from($type)) {
                QuestionType::SingleChoice => \App\Dto\CorrectAnswerPresentation::singleChoice('opt_a'),
                QuestionType::MultipleChoice => \App\Dto\CorrectAnswerPresentation::multipleChoice(['a', 'c']),
                QuestionType::TrueFalse => \App\Dto\CorrectAnswerPresentation::trueFalse(true),
                QuestionType::Numeric => \App\Dto\CorrectAnswerPresentation::numeric('3.14', '0.01'),
                QuestionType::ShortAnswer => \App\Dto\CorrectAnswerPresentation::shortAnswer(['paris', 'Paris']),
            };
            ($case['assert'])($presentation);
            $arr = $presentation->toArray();
            self::assertSame($type, $arr['questionType']);
            self::assertArrayNotHasKey('correctStableKey', $arr);
            self::assertArrayNotHasKey('correctStableKeys', $arr);
            self::assertArrayNotHasKey('acceptedAnswers', $arr);
        }
    }

    public function testCancelledDeliveryOmitsSensitiveFields(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy('arrr8');
        $delivery = $this->reloadDelivery($fx['delivery']->getId());
        $this->deliveries()->cancel(
            $delivery,
            $this->reloadUser($fx['owner']->getId()),
            'cancel_arrr8',
            'cancelled_for_review_test',
        );
        Clock::set(new MockClock($delivery->getClosesAt()->modify('+1 day')));

        $view = $this->reviewReader()->readReview(
            $this->reloadUser($fx['student']->getId()),
            $this->reloadAttempt($attempt->getId()),
        );
        self::assertNull($view->getItems()[0]->getCorrectAnswer());
        self::assertNull($view->getItems()[0]->getExplanation());
    }

    public function testTeacherAndOwnerCannotReadStudentReviewDto(): void
    {
        [$attempt, , $fx] = $this->submitScoreReleaseWithPolicy(
            'arrr_auth',
            showCorrectAnswer: false,
            showExplanation: false,
            showStudentAnswer: false,
        );

        try {
            $this->reviewReader()->readReview(
                $this->reloadUser($fx['teacher']->getId()),
                $this->reloadAttempt($attempt->getId()),
            );
            self::fail('Expected teacher unauthorized');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }

        try {
            $this->reviewReader()->readReview(
                $this->reloadUser($fx['owner']->getId()),
                $this->reloadAttempt($attempt->getId()),
            );
            self::fail('Expected owner unauthorized for student review DTO');
        } catch (AssessmentResultReviewException $e) {
            self::assertSame(AssessmentResultReviewFailureReason::Unauthorized, $e->getReason());
        }
    }
}

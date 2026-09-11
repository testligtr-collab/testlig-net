<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Tests\Support\AssessmentAnalyticsTestFixtures;
use App\Tests\Support\JsonKeyTree;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentAnalyticsSecurityLeakTest extends KernelTestCase
{
    use AssessmentAnalyticsTestFixtures;

    private const FORBIDDEN_KEYS = [
        'email',
        'password',
        'ciphertext',
        'nonce',
        'answerPayload',
        'answer_payload',
        'answerCiphertext',
        'answerNonce',
        'correctStableKey',
        'selectedStableKey',
        'hmac',
        'policyHash',
        'encryptionKey',
        'plaintext',
        'acceptedAnswers',
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

    public function testDtoTreesNeverLeakSecrets(): void
    {
        $cohort = $this->releaseClassroomCohort('aasl1', 5);
        $owner = $this->reloadUser($cohort['fx']['owner']->getId());
        $deliveryId = $cohort['fx']['delivery']->getId();

        $payloads = [
            $this->analyticsReader()->readAssessmentSummary($owner, $deliveryId)->toArray(),
            $this->analyticsReader()->readClassroomAnalytics($owner, $deliveryId)->toArray(),
            $this->analyticsReader()->readStudentAnalytics(
                $owner,
                $cohort['attempts'][0]->getId(),
            )->toArray(),
            array_map(
                static fn ($v) => $v->toArray(),
                $this->analyticsReader()->readQuestionAnalytics($owner, $deliveryId),
            ),
            array_map(
                static fn ($v) => $v->toArray(),
                $this->analyticsReader()->readLearningOutcomeAnalytics($owner, $deliveryId),
            ),
        ];

        foreach ($payloads as $payload) {
            $keys = JsonKeyTree::collectKeys($payload);
            foreach (self::FORBIDDEN_KEYS as $forbidden) {
                self::assertNotContains($forbidden, $keys, 'Leaked key: '.$forbidden);
            }
            $json = json_encode($payload, \JSON_THROW_ON_ERROR);
            foreach (['ciphertext', 'answerPayload', 'hmac', '@example.com'] as $needle) {
                self::assertStringNotContainsStringIgnoringCase($needle, $json);
            }
        }
    }

    public function testSuppressedSummaryOmitsSensitiveKeys(): void
    {
        $cohort = $this->releaseClassroomCohort('aasl2', 2);
        $payload = $this->analyticsReader()->readAssessmentSummary(
            $this->reloadUser($cohort['fx']['owner']->getId()),
            $cohort['fx']['delivery']->getId(),
        )->toArray();
        self::assertTrue($payload['suppressed']);
        self::assertArrayNotHasKey('averagePercentage', $payload);
        self::assertArrayNotHasKey('medianPercentage', $payload);
        self::assertArrayNotHasKey('minPercentage', $payload);
        self::assertArrayNotHasKey('maxPercentage', $payload);
        self::assertArrayNotHasKey('distribution', $payload);
        self::assertArrayNotHasKey('participationRate', $payload);
        self::assertArrayNotHasKey('completionRate', $payload);
    }
}

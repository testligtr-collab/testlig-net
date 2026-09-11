<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Tests\Support\AssessmentAnalyticsTestFixtures;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

final class AssessmentAnalyticsQueryBoundTest extends KernelTestCase
{
    use AssessmentAnalyticsTestFixtures;

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

    public function testQuestionAnalyticsBoundedQueries(): void
    {
        $cohort = $this->releaseClassroomCohort('aaqb1', 5);
        $owner = $this->reloadUser($cohort['fx']['owner']->getId());
        $deliveryId = $cohort['fx']['delivery']->getId();

        $counter = new class extends AbstractLogger {
            public int $count = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ++$this->count;
            }
        };

        $connection = $this->em->getConnection();
        $configuration = $connection->getConfiguration();
        $configuration->setMiddlewares(array_merge(
            [new LoggingMiddleware($counter)],
            $configuration->getMiddlewares(),
        ));
        $connection->close();

        $before = $counter->count;
        $views = $this->analyticsReader()->readQuestionAnalytics($owner, $deliveryId);
        $after = $counter->count;
        self::assertNotEmpty($views);
        self::assertLessThan(40, $after - $before, 'Question analytics should use bounded aggregate SQL (no per-row N+1).');
    }

    public function testAssessmentSummaryBoundedQueries(): void
    {
        $cohort = $this->releaseClassroomCohort('aaqb2', 5);
        $owner = $this->reloadUser($cohort['fx']['owner']->getId());
        $deliveryId = $cohort['fx']['delivery']->getId();

        $counter = new class extends AbstractLogger {
            public int $count = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ++$this->count;
            }
        };

        $connection = $this->em->getConnection();
        $configuration = $connection->getConfiguration();
        $configuration->setMiddlewares(array_merge(
            [new LoggingMiddleware($counter)],
            $configuration->getMiddlewares(),
        ));
        $connection->close();

        $before = $counter->count;
        $this->analyticsReader()->readAssessmentSummary($owner, $deliveryId);
        $after = $counter->count;
        self::assertLessThan(35, $after - $before, 'Summary analytics should stay bounded.');
    }
}

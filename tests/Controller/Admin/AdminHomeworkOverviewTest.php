<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Tests\Support\HomeworkTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminHomeworkOverviewTest extends WebTestCase
{
    use HomeworkTestFixtures;

    public function testOverviewShowsOnlyAssignedHomeworkAndSubmittedTotals(): void
    {
        $client = static::createClient();
        $this->rebindDeliveryFixtures();
        $this->cleanupHomework();

        $ctx = $this->homeworkContext('hw-admin-overview');
        [$submitted, $draft] = $this->enrollStudents($ctx, 2);
        $this->freezeClock();
        $assignment = $this->assignClassroomHomework($ctx, 'Kesirler ödevi');
        $this->studentSubmit($submitted->getUser(), $assignment, 'Gizli gönderilmiş yanıt');
        $this->studentDraft($draft->getUser(), $assignment, 'Gizli teslim taslağı');
        $this->persistDraft($ctx, 'Atanmamış ödev taslağı');

        $this->resetClock();
        $client->loginUser($ctx['sa']);
        $crawler = $client->request('GET', '/yonetim/bolum/odevler');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $rows = $crawler->filter('.admin-homework-overview tbody tr');
        self::assertCount(1, $rows);
        self::assertSame(
            ['Kesirler ödevi', $ctx['classroom']->getName(), 'Atandı', '1 / 2'],
            $rows->filter('td')->each(static fn (Crawler $cell): string => $cell->text()),
        );
        foreach (['Gizli gönderilmiş yanıt', 'Gizli teslim taslağı', 'Atanmamış ödev taslağı', $submitted->getUser()->getEmail(), $draft->getUser()->getEmail()] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $crawler->html());
        }
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        if (isset($this->em)) {
            $this->rebindDeliveryFixtures();
            $this->cleanupHomework();
        }
        parent::tearDown();
    }
}

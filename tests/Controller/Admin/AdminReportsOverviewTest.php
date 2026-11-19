<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Assessment;
use App\Entity\AssessmentItem;
use App\Entity\Subject;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionOrderMode;
use App\Enum\ResultReleasePolicy;
use App\Enum\UserRole;
use App\Repository\AssessmentRepository;
use App\Tests\Support\AssessmentDeliveryTestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminReportsOverviewTest extends WebTestCase
{
    use AssessmentDeliveryTestFixtures;

    public function testSuperAdminSeesPlatformRowsAndOpensResultsWithoutInstitutionLeak(): void
    {
        $client = static::createClient();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();

        $ctx = $this->publishedDeliveryContext('rpt-ov');
        $platformTitle = 'Delivery Blueprint rpt-ov';
        $institutionTitle = 'Kurum Gizli Rapor Testi rpt-ov';
        $this->createInstitutionDraft($ctx, $institutionTitle);

        $before = $this->assessmentsCount();
        $client->loginUser($ctx['sa']);
        $crawler = $client->request('GET', '/yonetim/bolum/raporlar');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertSelectorTextContains('h1', 'Raporlar');
        self::assertSelectorNotExists('.admin-stage');
        self::assertSame($before, $this->assessmentsCount(), 'GET must not write assessments');
        $rows = $crawler->filter('.admin-reports-page__table tbody tr');
        self::assertGreaterThanOrEqual(1, $rows->count());
        $html = $crawler->html();
        self::assertStringContainsString($platformTitle, $html);
        self::assertStringNotContainsString($institutionTitle, $html);
        self::assertStringNotContainsString($ctx['institution']->getName(), $html);

        $open = $crawler->filter('a.admin-reports-page__open')->reduce(
            static fn (Crawler $node): bool => str_contains($node->text(), $platformTitle),
        );
        self::assertGreaterThanOrEqual(1, $open->count());
        $href = $open->first()->attr('href');
        self::assertNotNull($href);
        self::assertSame(
            '/yonetim/testler/'.$ctx['assessment']->getId()->toRfc4122().'/sonuclar',
            $href,
        );
        $client->request('GET', $href);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $platformTitle);
    }

    public function testEmptyStateForSuperAdminWithoutPlatformAssessments(): void
    {
        $client = static::createClient();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();
        $sa = $this->activeUser('rpt-empty-sa@example.com', UserRole::SuperAdmin);

        $client->loginUser($sa);
        $client->request('GET', '/yonetim/bolum/raporlar');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Gösterilecek platform testi yok');
        self::assertSelectorNotExists('.admin-reports-page__table');
    }

    public function testDirectUrlRejectsAdminTeacherAndRedirectsAnonymous(): void
    {
        $client = static::createClient();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();

        foreach ([
            ['rpt-deny-admin@example.com', UserRole::Admin, 403],
            ['rpt-deny-teacher@example.com', UserRole::Teacher, 403],
        ] as [$email, $role, $status]) {
            $user = $this->activeUser($email, $role);
            $client->loginUser($user);
            $client->request('GET', '/yonetim/bolum/raporlar');
            self::assertResponseStatusCodeSame($status);
        }

        $anon = static::createClient();
        $anon->request('GET', '/yonetim/bolum/raporlar');
        self::assertResponseRedirects('/giris');
    }

    public function testNavMarksReportsReadyAndCurrentOnce(): void
    {
        $client = static::createClient();
        $this->rebindDeliveryFixtures();
        $this->cleanupDeliveryFixtures();
        $sa = $this->activeUser('rpt-nav-sa@example.com', UserRole::SuperAdmin);
        $client->loginUser($sa);

        $crawler = $client->request('GET', '/yonetim');
        self::assertResponseIsSuccessful();
        $link = $crawler->filter('a.admin-mini-link[href="/yonetim/bolum/raporlar"]');
        self::assertCount(1, $link);
        self::assertStringContainsString('Çalışıyor', $link->text());
        self::assertStringNotContainsString('Geliştirme aşamasında', $link->text());

        $crawler = $client->request('GET', '/yonetim/bolum/raporlar');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#panel-sidebar a[href="/yonetim/bolum/raporlar"][aria-current="page"]'));
        self::assertNotNull($crawler->filter('#nav-group-learning')->attr('open'));
    }

    /**
     * @param array{owner: \App\Entity\User, institution: \App\Entity\Institution, assessment: Assessment} $ctx
     */
    private function createInstitutionDraft(array $ctx, string $title): void
    {
        $revision = $ctx['assessment']->getPublishedRevision();
        self::assertNotNull($revision);
        $item = $this->em->createQueryBuilder()
            ->select('item', 'questionRevision', 'question')
            ->from(AssessmentItem::class, 'item')
            ->innerJoin('item.questionRevision', 'questionRevision')
            ->innerJoin('questionRevision.question', 'question')
            ->andWhere('item.assessmentRevision = :revision')
            ->setParameter('revision', $revision->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(AssessmentItem::class, $item);
        $subject = $ctx['assessment']->getSubject();
        self::assertInstanceOf(Subject::class, $subject);
        $this->assessments()->createDraftAssessment(
            $ctx['owner'],
            AssessmentScope::Institution,
            $ctx['institution'],
            AssessmentType::Quiz,
            GradeLevel::Grade9,
            $title,
            null,
            null,
            null,
            NavigationMode::Free,
            QuestionOrderMode::Fixed,
            OptionOrderMode::Fixed,
            ResultReleasePolicy::Immediate,
            null,
            [$this->sectionWithItem($item->getQuestion(), $item->getQuestionRevision())],
            'create_inst_rpt',
            $subject,
        );
    }

    private function assessmentsCount(): int
    {
        $repo = static::getContainer()->get(AssessmentRepository::class);
        self::assertInstanceOf(AssessmentRepository::class, $repo);

        return $repo->count([]);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->rebindDeliveryFixtures();
            $this->cleanupDeliveryFixtures();
        }
        parent::tearDown();
    }
}

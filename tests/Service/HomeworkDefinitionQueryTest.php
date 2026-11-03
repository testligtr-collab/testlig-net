<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\HomeworkDraftPage;
use App\Enum\GradeLevel;
use App\Enum\TeacherAssignmentRole;
use App\Service\Homework\HomeworkDefinitionQuery;
use App\Service\Homework\HomeworkTeacherScope;
use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HomeworkDefinitionQueryTest extends KernelTestCase
{
    use HomeworkTestFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupHomework();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        if (isset($this->em)) {
            $this->cleanupHomework();
        }
        parent::tearDown();
    }

    public function testListsOnlyOwnDraftsWithCountMatchingTheSameFilter(): void
    {
        $ctx = $this->homeworkContext('hwq-own');
        $co = $this->addCoTeacher($ctx, 'hwq-own-co@example.com');
        $this->persistDraft($ctx, 'Kesirler');
        $this->persistDraft($ctx, 'Ondalıklar');
        $this->persistDraft(['teacher' => $co['user'], 'teacherMembership' => $co['membership'], 'classroom' => $ctx['classroom']], 'Yardımcı ödevi');

        $query = $this->homeworkQuery();
        $page = $query->page($ctx['teacher'], '', null, 1);
        self::assertSame(2, $page->total);
        self::assertSame(2, $query->count($ctx['teacher']));
        self::assertEqualsCanonicalizing(['Kesirler', 'Ondalıklar'], array_map(static fn ($row) => $row->title, $page->rows));
        self::assertSame($ctx['classroom']->getName(), $page->rows[0]->classroomName);
        self::assertSame($ctx['institution']->getName(), $page->rows[0]->institutionName);

        $coPage = $query->page($co['user'], '', null, 1);
        self::assertSame(1, $coPage->total);
        self::assertSame('Yardımcı ödevi', $coPage->rows[0]->title);
    }

    public function testEmptyListAndEmptySearchAreDistinct(): void
    {
        $ctx = $this->homeworkContext('hwq-empty');
        $query = $this->homeworkQuery();

        $empty = $query->page($ctx['teacher'], '', null, 1);
        self::assertSame(0, $empty->total);
        self::assertSame([], $empty->rows);
        self::assertSame(1, $empty->pageCount);

        $this->persistDraft($ctx, 'Kesirler');
        self::assertSame(0, $query->page($ctx['teacher'], 'bulunmayan', null, 1)->total);
        self::assertSame(1, $query->count($ctx['teacher']));
    }

    public function testSearchEscapesLikeWildcardsAndMatchesTitleOnly(): void
    {
        $ctx = $this->homeworkContext('hwq-like');
        $this->persistDraft($ctx, 'Yüzde 100% ödev');
        $this->persistDraft($ctx, 'Yüzde 100X ödev');
        $this->persistDraft($ctx, 'a_b');
        $this->persistDraft($ctx, 'axb');
        $this->persistDraft($ctx, 'Dikkat\\ters');
        $query = $this->homeworkQuery();

        self::assertSame(['Yüzde 100% ödev'], $this->titles($query->page($ctx['teacher'], '100%', null, 1)));
        self::assertSame(['a_b'], $this->titles($query->page($ctx['teacher'], 'a_b', null, 1)));
        self::assertSame(['Dikkat\\ters'], $this->titles($query->page($ctx['teacher'], 't\\t', null, 1)));
        // A literal percent sign matches only the title that contains one; it is not a wildcard.
        self::assertSame(1, $query->count($ctx['teacher'], '%'));
        self::assertSame(2, $query->count($ctx['teacher'], 'yüzde'));
        // The description holds "Açıklama <title>" but is never searched.
        self::assertSame(0, $query->count($ctx['teacher'], 'Açıklama'));
    }

    public function testClassroomFilterAndPagination(): void
    {
        $ctx = $this->homeworkContext('hwq-filter');
        $second = $this->classroomManager()->create($ctx['year'], $ctx['owner'], 'hwq-filter 9-B', GradeLevel::Grade9, 'cls2', 'B', 40);
        $this->teacherManager()->assign($second, $ctx['owner'], $ctx['teacherMembership'], TeacherAssignmentRole::AssistantTeacher, 'assign_b');
        $this->persistDraft($ctx, 'A sınıfı ödevi');
        $this->persistDraft($ctx, 'B sınıfı ödevi', classroom: $second);

        $query = $this->homeworkQuery();
        $filtered = $query->page($ctx['teacher'], '', $second->getId(), 1);
        self::assertSame(['B sınıfı ödevi'], $this->titles($filtered));
        self::assertSame(1, $query->count($ctx['teacher'], '', $second->getId()));
        self::assertSame(2, $query->count($ctx['teacher']));

        for ($i = 1; $i <= 21; ++$i) {
            $this->persistDraft($ctx, \sprintf('Toplu %02d', $i));
        }
        $first = $query->page($ctx['teacher'], 'Toplu', null, 1);
        $last = $query->page($ctx['teacher'], 'Toplu', null, 9);
        self::assertSame(21, $first->total);
        self::assertSame(2, $first->pageCount);
        self::assertCount(HomeworkDefinitionQuery::PAGE_SIZE, $first->rows);
        self::assertSame(2, $last->page);
        self::assertCount(1, $last->rows);
    }

    public function testClassroomOptionsAreBatchLoadedForAuthorizedClassroomsOnly(): void
    {
        $ctx = $this->homeworkContext('hwq-opts');
        $other = $this->homeworkContext('hwq-opts2');
        $scope = static::getContainer()->get(HomeworkTeacherScope::class);
        self::assertInstanceOf(HomeworkTeacherScope::class, $scope);

        $options = $scope->classrooms($ctx['teacher'], true);
        self::assertCount(1, $options);
        self::assertSame($ctx['classroom']->getId()->toRfc4122(), $options[0]->id);
        self::assertSame($ctx['classroom']->getName(), $options[0]->label(false));
        self::assertSame([], $scope->classrooms($ctx['owner'], true));
        self::assertSame([], $scope->classrooms($ctx['sa'], true));
        self::assertNotSame($options[0]->id, $scope->classrooms($other['teacher'], true)[0]->id);

        $this->teacherManager()->endAssignment($ctx['assignment'], $ctx['owner'], 'ended');
        self::assertSame([], $scope->classrooms($ctx['teacher'], true));
        self::assertSame([], $scope->classrooms($ctx['teacher'], false));
    }

    public function testSuspendedInstitutionHidesDrafts(): void
    {
        $ctx = $this->homeworkContext('hwq-inst');
        $draft = $this->persistDraft($ctx, 'Kurum askıda');
        self::assertSame(1, $this->homeworkQuery()->count($ctx['teacher']));

        $this->institutionStatus()->suspend($ctx['institution'], $ctx['sa'], 'suspend');

        self::assertSame(0, $this->homeworkQuery()->count($ctx['teacher']));
        self::assertNull($this->homeworkQuery()->findOwned($ctx['teacher'], $draft->getId()));
    }

    public function testListQueryCountDoesNotGrowWithRowCount(): void
    {
        $ctx = $this->homeworkContext('hwq-bound');
        $query = $this->homeworkQuery();
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

        for ($i = 1; $i <= 3; ++$i) {
            $this->persistDraft($ctx, 'Az '.$i);
        }
        $this->em->clear();
        $query->page($ctx['teacher'], '', null, 1); // warm-up (metadata / auth lookups)
        $before = $counter->count;
        $small = $query->page($ctx['teacher'], '', null, 1);
        $smallQueries = $counter->count - $before;

        for ($i = 1; $i <= 15; ++$i) {
            $this->persistDraft($ctx, 'Çok '.$i);
        }
        $this->em->clear();
        $before = $counter->count;
        $large = $query->page($ctx['teacher'], '', null, 1);
        $largeQueries = $counter->count - $before;

        self::assertCount(3, $small->rows);
        self::assertCount(18, $large->rows);
        self::assertGreaterThan(0, $smallQueries);
        self::assertSame($smallQueries, $largeQueries, 'List SQL must not grow with the number of rows (no N+1).');
        self::assertLessThanOrEqual(8, $largeQueries);
    }

    /**
     * @return list<string>
     */
    private function titles(HomeworkDraftPage $page): array
    {
        return array_map(static fn ($row): string => $row->title, $page->rows);
    }
}

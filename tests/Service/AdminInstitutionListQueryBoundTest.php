<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\AdminInstitutionListItemView;
use App\Entity\User;
use App\Enum\InstitutionType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Service\Admin\AdminInstitutionQuery;
use App\Service\InstitutionCreator;
use App\Service\UserFactory;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminInstitutionListQueryBoundTest extends KernelTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testListQueryCountDoesNotGrowWithRowsAndSummaryIgnoresFilters(): void
    {
        $actor = $this->user('admin_inst_bound_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('admin_inst_bound_owner@example.com', UserRole::Teacher);
        $this->institution($actor, $owner, 'Admin Inst Bound One');

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

        $query = $this->query();
        $actorId = $actor->getId();
        $query->listInstitutions($actorId, ['q' => 'admin-inst-bound']);

        $before = $counter->count;
        $one = $query->listInstitutions($actorId, ['q' => 'admin-inst-bound']);
        $oneQueries = $counter->count - $before;
        self::assertCount(1, $one->items);
        $first = $one->items[0] ?? null;
        self::assertInstanceOf(AdminInstitutionListItemView::class, $first);
        self::assertSame(1, $first->activeMemberCount);

        $this->institution($actor, $owner, 'Admin Inst Bound Two');
        $this->institution($actor, $owner, 'Admin Inst Bound Three');

        $summary = $query->summarize($actorId);
        $before = $counter->count;
        $three = $query->listInstitutions($actorId, ['q' => 'admin-inst-bound']);
        $threeQueries = $counter->count - $before;
        $filteredSummary = $query->summarize($actorId);

        self::assertCount(3, $three->items);
        $listed = $three->items[0] ?? null;
        self::assertInstanceOf(AdminInstitutionListItemView::class, $listed);
        self::assertSame($oneQueries, $threeQueries);
        self::assertLessThan(12, $threeQueries);
        self::assertSame(
            [$summary->total, $summary->active, $summary->pending, $summary->activeMemberships],
            [$filteredSummary->total, $filteredSummary->active, $filteredSummary->pending, $filteredSummary->activeMemberships],
        );
        $detail = $query->getDetail($actorId, $listed->id);
        self::assertSame($listed->activeMemberCount, $detail->activeMemberCount);
        self::assertSame(1, $detail->activeOwnerCount);
    }

    private function query(): AdminInstitutionQuery
    {
        $query = static::getContainer()->get(AdminInstitutionQuery::class);
        self::assertInstanceOf(AdminInstitutionQuery::class, $query);

        return $query;
    }

    private function institution(User $actor, User $owner, string $name): void
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $freshActor = $users->findOneById($actor->getId());
        $freshOwner = $users->findOneById($owner->getId());
        self::assertInstanceOf(User::class, $freshActor);
        self::assertInstanceOf(User::class, $freshOwner);
        $creator = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);
        $creator->create($freshActor, $freshOwner, $name, InstitutionType::School, 'platform_setup');
        $this->em->clear();
    }

    private function user(string $email, UserRole $role): User
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, 'Bound', 'Sahip', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $this->em->flush();

        return $user;
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        if (!$connection->createSchemaManager()->tablesExist(['institutions'])) {
            return;
        }
        $connection->executeStatement(
            "DELETE im FROM institution_memberships im INNER JOIN institutions i ON i.id = im.institution_id WHERE i.normalized_name LIKE 'admin inst bound%'",
        );
        $connection->executeStatement(
            "DELETE FROM institutions WHERE normalized_name LIKE 'admin inst bound%'",
        );
        if ($connection->createSchemaManager()->tablesExist(['users'])) {
            $connection->executeStatement(
                "DELETE FROM users WHERE normalized_email LIKE 'admin_inst_bound_%@example.com'",
            );
        }
    }
}

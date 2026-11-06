<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Institution;
use App\Entity\InstitutionBranch;
use App\Entity\User;
use App\Enum\InstitutionBranchKind;
use App\Enum\InstitutionBranchStatus;
use App\Enum\InstitutionType;
use App\Enum\SecurityAuditAction;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\InstitutionOperationException;
use App\Repository\InstitutionBranchRepository;
use App\Repository\SecurityAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\Admin\AdminBranchQuery;
use App\Service\InstitutionBranchCreator;
use App\Service\InstitutionBranchEditor;
use App\Service\InstitutionBranchStatusManager;
use App\Service\InstitutionCreator;
use App\Service\InstitutionStatusManager;
use App\Service\UserFactory;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstitutionBranchDomainTest extends KernelTestCase
{
    private const PASSWORD = 'Guclu-Parola-123!';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function testCodeIsUniqueInsideOneInstitutionAndReusableInAnother(): void
    {
        $actor = $this->user('branch_code_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('branch_code_owner@example.com', UserRole::Teacher);
        $first = $this->institution($actor, $owner, 'Branch Code One');
        $second = $this->institution($actor, $owner, 'Branch Code Two');
        $memberships = $this->membershipCount($first);

        $creator = $this->creator();
        $created = $creator->create($actor, $first->getId(), 'Merkez', 'mrk-01', InstitutionBranchKind::Branch, 'institution_onboarding');
        $again = $creator->create($actor, $second->getId(), 'Merkez', 'MRK-01', InstitutionBranchKind::Campus, 'operator_setup');

        self::assertSame(InstitutionBranchStatus::Pending, $created->getStatus());
        self::assertSame('MRK-01', $created->getCode());
        self::assertSame($first->getId()->toRfc4122(), $created->getInstitution()->getId()->toRfc4122());
        self::assertSame($second->getId()->toRfc4122(), $again->getInstitution()->getId()->toRfc4122());
        self::assertSame($memberships, $this->membershipCount($first));
        self::assertSame(1, $this->audit(SecurityAuditAction::InstitutionBranchCreated, $created->getId()->toRfc4122()));

        try {
            $creator->create($actor, $first->getId(), 'Diger', 'mrk-01', InstitutionBranchKind::Branch, 'institution_onboarding');
            self::fail('Duplicate code was accepted.');
        } catch (InstitutionOperationException $e) {
            self::assertSame('conflict', $e->getReason()->value);
        }
        self::assertSame(1, $this->countCode($first, 'MRK-01'));
        self::assertSame(1, $this->audit(SecurityAuditAction::InstitutionBranchCreated, $created->getId()->toRfc4122()));
    }

    public function testActivationRequiresAnActiveInstitutionAndALegalTransition(): void
    {
        $actor = $this->user('branch_status_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('branch_status_owner@example.com', UserRole::Teacher);
        $institution = $this->institution($actor, $owner, 'Branch Status School');
        $branch = $this->creator()->create($actor, $institution->getId(), 'Kampus', 'KMP-01', InstitutionBranchKind::Campus, 'institution_onboarding');

        try {
            $this->branchStatus()->activate($branch->getId(), $actor, 'lifecycle_change');
            self::fail('Pending institution allowed activation.');
        } catch (InstitutionOperationException $e) {
            self::assertSame('institution_not_active', $e->getReason()->value);
        }
        $this->reboot();
        self::assertSame(InstitutionBranchStatus::Pending, $this->reload($branch->getId())->getStatus());
        self::assertSame(0, $this->audit(SecurityAuditAction::InstitutionBranchStatusChanged, $branch->getId()->toRfc4122()));

        $this->institutionStatus()->activate($institution, $actor, 'lifecycle_change');
        $this->branchStatus()->activate($branch->getId(), $actor, 'lifecycle_change');
        self::assertSame(InstitutionBranchStatus::Active, $this->reload($branch->getId())->getStatus());
        self::assertSame(1, $this->audit(SecurityAuditAction::InstitutionBranchStatusChanged, $branch->getId()->toRfc4122()));

        try {
            $this->branchStatus()->activate($branch->getId(), $actor, 'lifecycle_change');
            self::fail('Repeated activation was accepted.');
        } catch (InstitutionOperationException $e) {
            self::assertSame('invalid_transition', $e->getReason()->value);
        }
        $this->reboot();
        self::assertSame(InstitutionBranchStatus::Active, $this->reload($branch->getId())->getStatus());
        self::assertSame(1, $this->audit(SecurityAuditAction::InstitutionBranchStatusChanged, $branch->getId()->toRfc4122()));
    }

    public function testEditKeepsTheInstitutionAndPlainAdminCannotCreate(): void
    {
        $actor = $this->user('branch_edit_sa@example.com', UserRole::SuperAdmin);
        $admin = $this->user('branch_edit_admin@example.com', UserRole::Admin);
        $owner = $this->user('branch_edit_owner@example.com', UserRole::Teacher);
        $institution = $this->institution($actor, $owner, 'Branch Edit School');
        $other = $this->institution($actor, $owner, 'Branch Edit Other');
        $branch = $this->creator()->create($actor, $institution->getId(), 'Sube', 'SUB-01', InstitutionBranchKind::Branch, 'institution_onboarding');
        $before = $this->auditTotal();

        try {
            $this->creator()->create($admin, $institution->getId(), 'Yeni', 'SUB-02', InstitutionBranchKind::Branch, 'institution_onboarding');
            self::fail('Admin created a branch.');
        } catch (InstitutionOperationException $e) {
            self::assertSame('unauthorized', $e->getReason()->value);
        }
        $this->reboot();
        self::assertSame($before, $this->auditTotal());
        self::assertSame(0, $this->countCode($institution, 'SUB-02'));

        $this->editor()->update($branch->getId(), $actor, 'Yeni Ad', 'sub-09', InstitutionBranchKind::Campus, 'record_correction');
        $fresh = $this->reload($branch->getId());
        self::assertSame('Yeni Ad', $fresh->getName());
        self::assertSame('SUB-09', $fresh->getCode());
        self::assertSame(InstitutionBranchKind::Campus, $fresh->getKind());
        self::assertSame($institution->getId()->toRfc4122(), $fresh->getInstitution()->getId()->toRfc4122());
        self::assertNotSame($other->getId()->toRfc4122(), $fresh->getInstitution()->getId()->toRfc4122());
        self::assertSame(1, $this->audit(SecurityAuditAction::InstitutionBranchUpdated, $branch->getId()->toRfc4122()));
    }

    public function testListCountersIgnoreFiltersAndDoNotGrowWithRows(): void
    {
        $actor = $this->user('branch_query_sa@example.com', UserRole::SuperAdmin);
        $owner = $this->user('branch_query_owner@example.com', UserRole::Teacher);
        $institution = $this->institution($actor, $owner, 'Branch Query School');
        $this->institutionStatus()->activate($institution, $actor, 'lifecycle_change');
        $creator = $this->creator();
        $first = $creator->create($actor, $institution->getId(), 'Bir', 'QRY-01', InstitutionBranchKind::Branch, 'institution_onboarding');
        $this->branchStatus()->activate($first->getId(), $actor, 'lifecycle_change');

        $counter = new class extends AbstractLogger {
            public int $count = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ++$this->count;
            }
        };
        $connection = $this->em->getConnection();
        $configuration = $connection->getConfiguration();
        $configuration->setMiddlewares(array_merge([new LoggingMiddleware($counter)], $configuration->getMiddlewares()));
        $connection->close();

        $query = $this->query();
        $baseline = $query->summarize($actor->getId());
        $query->listBranches($actor->getId(), ['q' => 'QRY-']);
        $before = $counter->count;
        $one = $query->listBranches($actor->getId(), ['q' => 'QRY-']);
        $oneQueries = $counter->count - $before;

        $creator->create($actor, $institution->getId(), 'Iki', 'QRY-02', InstitutionBranchKind::Campus, 'institution_onboarding');
        $creator->create($actor, $institution->getId(), 'Uc', 'QRY-03', InstitutionBranchKind::Branch, 'institution_onboarding');
        $summary = $query->summarize($actor->getId());
        $before = $counter->count;
        $three = $query->listBranches($actor->getId(), ['q' => 'QRY-', 'page_size' => 1, 'page' => 2]);
        $threeQueries = $counter->count - $before;
        $again = $query->summarize($actor->getId());

        self::assertSame($oneQueries, $threeQueries);
        self::assertLessThan(8, $threeQueries);
        self::assertSame(1, \count($one->items));
        self::assertSame(1, \count($three->items));
        self::assertSame(3, $three->totalCount);
        self::assertSame(3, $three->totalPages());
        self::assertSame(1, $one->totalCount);
        self::assertSame($baseline->total + 2, $summary->total);
        self::assertSame($baseline->active, $summary->active);
        self::assertSame($baseline->pending + 2, $summary->pending);
        self::assertSame($baseline->linkedInstitutions, $summary->linkedInstitutions);
        self::assertSame(
            [$summary->total, $summary->active, $summary->pending, $summary->linkedInstitutions],
            [$again->total, $again->active, $again->pending, $again->linkedInstitutions],
        );
        $detail = $query->getDetail($actor->getId(), $first->getId());
        self::assertTrue($detail->accessOpen);
        self::assertSame($institution->getId()->toRfc4122(), $detail->institutionId);
    }

    private function reboot(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    private function creator(): InstitutionBranchCreator
    {
        $service = static::getContainer()->get(InstitutionBranchCreator::class);
        self::assertInstanceOf(InstitutionBranchCreator::class, $service);

        return $service;
    }

    private function editor(): InstitutionBranchEditor
    {
        $service = static::getContainer()->get(InstitutionBranchEditor::class);
        self::assertInstanceOf(InstitutionBranchEditor::class, $service);

        return $service;
    }

    private function branchStatus(): InstitutionBranchStatusManager
    {
        $service = static::getContainer()->get(InstitutionBranchStatusManager::class);
        self::assertInstanceOf(InstitutionBranchStatusManager::class, $service);

        return $service;
    }

    private function institutionStatus(): InstitutionStatusManager
    {
        $service = static::getContainer()->get(InstitutionStatusManager::class);
        self::assertInstanceOf(InstitutionStatusManager::class, $service);

        return $service;
    }

    private function query(): AdminBranchQuery
    {
        $service = static::getContainer()->get(AdminBranchQuery::class);
        self::assertInstanceOf(AdminBranchQuery::class, $service);

        return $service;
    }

    private function user(string $email, UserRole $role): User
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $initial = $role->isPrivilegedBootstrapRole() ? UserRole::Teacher : $role;
        $user = $factory->createAndPersist($email, self::PASSWORD, 'Ad', 'Soyad', $initial);
        $user->markEmailVerified(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $user->transitionTo(UserStatus::Active);
        if ($initial !== $role) {
            $user->addGlobalRole($role);
        }
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $users->save($user);

        return $user;
    }

    private function institution(User $actor, User $owner, string $name): Institution
    {
        $creator = static::getContainer()->get(InstitutionCreator::class);
        self::assertInstanceOf(InstitutionCreator::class, $creator);

        return $creator->create($actor, $owner, $name, InstitutionType::School, 'platform_setup');
    }

    private function reload(\Symfony\Component\Uid\Uuid $id): InstitutionBranch
    {
        $this->em->clear();
        $branches = static::getContainer()->get(InstitutionBranchRepository::class);
        self::assertInstanceOf(InstitutionBranchRepository::class, $branches);
        $branch = $branches->findOneById($id);
        self::assertInstanceOf(InstitutionBranch::class, $branch);

        return $branch;
    }

    private function countCode(Institution $institution, string $code): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(b.id) FROM App\Entity\InstitutionBranch b WHERE IDENTITY(b.institution) = :institution AND b.normalizedCode = :code',
        )
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->setParameter('code', $code)
            ->getSingleScalarResult();
    }

    private function membershipCount(Institution $institution): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(m.id) FROM App\Entity\InstitutionMembership m WHERE IDENTITY(m.institution) = :institution',
        )
            ->setParameter('institution', $institution->getId(), 'uuid')
            ->getSingleScalarResult();
    }

    private function audit(SecurityAuditAction $action, string $branchId): int
    {
        $events = static::getContainer()->get(SecurityAuditEventRepository::class);
        self::assertInstanceOf(SecurityAuditEventRepository::class, $events);

        return $events->countByAction($action->value) > 0
            ? (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM security_audit_events WHERE action = ? AND metadata LIKE ?',
                [$action->value, '%'.$branchId.'%'],
            )
            : 0;
    }

    private function auditTotal(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM security_audit_events');
    }
}

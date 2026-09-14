<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\CommerceFailureReason;
use App\Exception\CommerceException;
use App\Repository\SecurityAuditEventRepository;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\MockClock;

/**
 * Shared bootstrap helpers for Stage 2.17 commerce tests.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait CommerceTestFixtures
{
    private EntityManagerInterface $em;
    private CommerceScenario $scenario;
    private MockClock $clock;

    private function bootCommerce(string $now = '2026-09-13 12:00:00'): void
    {
        self::bootKernel();
        $this->rebindCommerce();
        $this->clock = new MockClock($now);
        \Symfony\Component\Clock\Clock::set($this->clock);
        $this->cleanupCommerce();
    }

    private function rebindCommerce(): void
    {
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->scenario = new CommerceScenario(static::getContainer(), $this->em);
    }

    /**
     * Managers run inside `wrapInTransaction`, which closes the EntityManager on any
     * rollback, so every expected failure needs the Doctrine reset the Stage 2.16 tests
     * use. Entities captured before the reset stay usable because managers only read
     * their identifiers and reload locked copies inside the transaction.
     */
    private function recoverDoctrine(): void
    {
        if ($this->em->isOpen()) {
            return;
        }

        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->rebindCommerce();
    }

    private function expectCommerceFailure(CommerceFailureReason $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected CommerceException '.$reason->value);
        } catch (CommerceException $e) {
            self::assertSame($reason, $e->getReason(), 'Unexpected failure reason: '.$e->getMessage());
        } finally {
            $this->recoverDoctrine();
        }
    }

    private function expectDatabaseRejection(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected the database to reject the statement.');
        } catch (DbalException $e) {
            self::assertNotSame('', $e->getMessage());
        } finally {
            $this->recoverDoctrine();
        }
    }

    private function auditEvents(): SecurityAuditEventRepository
    {
        return $this->scenario->service(SecurityAuditEventRepository::class);
    }

    private function cleanupCommerce(): void
    {
        $connection = $this->em->getConnection();
        CommerceDbCleanup::deleteAll($connection);
        AccessEntitlementDbCleanup::deleteAll($connection);
        if ($connection->createSchemaManager()->tablesExist(['curriculum_topics'])) {
            $connection->executeStatement('DELETE FROM curriculum_topics WHERE parent_id IS NOT NULL');
        }
        QuestionBankDbCleanup::deleteTables($connection, [
            'curriculum_learning_outcomes',
            'curriculum_topics',
            'curriculum_units',
            'curriculum_programs',
            'subjects',
            'institution_memberships',
            'institutions',
            'security_audit_events',
            'security_bootstrap_guards',
            'reset_password_requests',
            'users',
        ]);
    }
}

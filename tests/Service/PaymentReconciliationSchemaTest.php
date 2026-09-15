<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Schema-level guarantees for Stage 2.19 reconciliation tables / inbox requeue trigger.
 */
final class PaymentReconciliationSchemaTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function testMigrationVersion20260915120000IsPresent(): void
    {
        $path = \dirname(__DIR__, 2).'/migrations/Version20260915120000.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('payment_reconciliation_runs', $source);
        self::assertStringContainsString('payment_reconciliation_items', $source);
        self::assertStringContainsString('dead_letter', $source);
        self::assertStringContainsString('retry_pending', $source);
        self::assertStringContainsString('irreversible', $source);
        self::assertStringNotContainsString('@testlig', $source);
        self::assertStringNotContainsString('FOREIGN_KEY_CHECKS', $source);
    }

    public function testReconciliationTablesExistWithChecksAndUniques(): void
    {
        $schema = $this->connection()->createSchemaManager();
        self::assertTrue($schema->tablesExist(['payment_reconciliation_runs', 'payment_reconciliation_items']));

        $runChecks = $this->connection()->fetchFirstColumn(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = "CHECK"',
            ['payment_reconciliation_runs'],
        );
        foreach ([
            'chk_prr_mode',
            'chk_prr_status',
            'chk_prr_environment',
            'chk_prr_counters_nonneg',
            'chk_prr_counters_sum',
            'chk_prr_completion_null_pair',
        ] as $expected) {
            self::assertContains($expected, $runChecks, $expected.' must exist.');
        }

        $itemUniques = $this->connection()->fetchFirstColumn(
            'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0',
            ['payment_reconciliation_items'],
        );
        self::assertContains('uniq_pri_run_attempt', $itemUniques);
    }

    public function testTriggersExistAreBeforeAndCarryNoBypass(): void
    {
        foreach (['payment_reconciliation_runs', 'payment_reconciliation_items'] as $table) {
            $triggers = $this->connection()->fetchAllAssociative(
                'SELECT TRIGGER_NAME, ACTION_TIMING, ACTION_STATEMENT
                   FROM information_schema.TRIGGERS
                  WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
                [$table],
            );
            self::assertNotEmpty($triggers, $table.' must have triggers.');
            foreach ($triggers as $trigger) {
                self::assertSame('BEFORE', $trigger['ACTION_TIMING']);
                $body = strtolower((string) $trigger['ACTION_STATEMENT']);
                self::assertStringNotContainsString('bypass', $body);
                self::assertStringNotContainsString('@testlig', $body);
                self::assertStringNotContainsString('foreign_key_checks', $body);
            }
        }

        $lifecycle = $this->connection()->fetchOne(
            'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = "trg_pwie_bu_lifecycle"',
        );
        self::assertIsString($lifecycle);
        self::assertStringContainsString('dead_letter', $lifecycle);
        self::assertStringContainsString('retry_pending', $lifecycle);
        self::assertStringContainsString('attempt_count', $lifecycle);
    }

    public function testItemsAreAppendOnlyAndRunsDenyDelete(): void
    {
        $runId = Uuid::v7()->toBinary();
        $this->connection()->executeStatement(
            'INSERT INTO payment_reconciliation_runs (
                id, provider_code, environment, mode, status, started_at, reason_code, schema_version
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $runId,
                'sandbox_provider',
                'sandbox',
                'manual',
                'running',
                '2026-09-13 12:00:00',
                'schema_test',
                1,
            ],
        );

        $this->expectDatabaseRejection(function () use ($runId): void {
            $this->connection()->executeStatement(
                'DELETE FROM payment_reconciliation_runs WHERE id = ?',
                [$runId],
            );
        });

        $attemptId = $this->connection()->fetchOne('SELECT id FROM payment_attempts LIMIT 1');
        if (!\is_string($attemptId) || '' === $attemptId) {
            // No attempt row available — still verify UPDATE deny via a synthetic insert when possible.
            $this->connection()->executeStatement(
                'UPDATE payment_reconciliation_runs
                    SET status = ?, completed_at = ?, checked_count = 0, matched_count = 0,
                        discrepancy_count = 0, failed_count = 0
                  WHERE id = ?',
                ['completed', '2026-09-13 12:01:00', $runId],
            );
            $this->expectDatabaseRejection(function () use ($runId): void {
                $this->connection()->executeStatement(
                    'UPDATE payment_reconciliation_runs SET reason_code = ? WHERE id = ?',
                    ['mutated', $runId],
                );
            });

            return;
        }

        $itemId = Uuid::v7()->toBinary();
        $this->connection()->executeStatement(
            'INSERT INTO payment_reconciliation_items (
                id, run_id, payment_attempt_id, expected_state, provider_state, outcome, action,
                checked_at, schema_version
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $itemId,
                $runId,
                $attemptId,
                'initiated',
                'initiated',
                'matched',
                'none',
                '2026-09-13 12:00:00',
                1,
            ],
        );

        $this->expectDatabaseRejection(function () use ($itemId): void {
            $this->connection()->executeStatement(
                'UPDATE payment_reconciliation_items SET outcome = ? WHERE id = ?',
                ['failed', $itemId],
            );
        });
        $this->expectDatabaseRejection(function () use ($itemId): void {
            $this->connection()->executeStatement(
                'DELETE FROM payment_reconciliation_items WHERE id = ?',
                [$itemId],
            );
        });
    }

    private function expectDatabaseRejection(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected the database to reject the statement.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}

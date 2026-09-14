<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Schema-level guarantees for payment_webhook_inbox_events (Stage 2.18).
 */
final class PaymentWebhookInboxSchemaTest extends KernelTestCase
{
    private const TABLE = 'payment_webhook_inbox_events';

    /**
     * @var list<string>
     */
    private const EXPECTED_TRIGGERS = [
        'trg_pwie_bu_lifecycle',
        'trg_pwie_bd_deny',
    ];

    /**
     * @var list<string>
     */
    private const EXPECTED_UNIQUE_INDEXES = [
        'uniq_pwie_provider_env_event_ref',
    ];

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

    public function testMigrationVersion20260914120000IsPresent(): void
    {
        $path = \dirname(__DIR__, 2).'/migrations/Version20260914120000.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('payment_webhook_inbox_events', $source);
        self::assertStringContainsString('uniq_pwie_provider_env_event_ref', $source);
        self::assertStringContainsString('trg_pwie_bu_lifecycle', $source);
        self::assertStringContainsString('trg_pwie_bd_deny', $source);
    }

    public function testInboxTableExistsWithCheckConstraints(): void
    {
        self::assertTrue($this->connection()->createSchemaManager()->tablesExist([self::TABLE]));

        $checks = $this->connection()->fetchFirstColumn(
            'SELECT CONSTRAINT_NAME
               FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND CONSTRAINT_TYPE = "CHECK"',
            [self::TABLE],
        );
        self::assertGreaterThanOrEqual(8, \count($checks));
        foreach ([
            'chk_pwie_environment',
            'chk_pwie_event_type',
            'chk_pwie_status',
            'chk_pwie_payload_hash',
            'chk_pwie_signature_fingerprint',
            'chk_pwie_provider_code',
            'chk_pwie_provider_event_reference',
            'chk_pwie_processed_null_pair',
        ] as $expected) {
            self::assertContains($expected, $checks, $expected.' must exist.');
        }
    }

    public function testDocumentedUniqueIndexesExist(): void
    {
        $indexes = $this->connection()->fetchFirstColumn(
            'SELECT DISTINCT INDEX_NAME
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND NON_UNIQUE = 0',
            [self::TABLE],
        );
        foreach (self::EXPECTED_UNIQUE_INDEXES as $expected) {
            self::assertContains($expected, $indexes, $expected.' must exist as a unique index.');
        }
    }

    public function testInboxTriggersExistAreBeforeAndCarryNoBypass(): void
    {
        $triggers = $this->connection()->fetchAllAssociative(
            'SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE()
                AND EVENT_OBJECT_TABLE = ?
              ORDER BY TRIGGER_NAME',
            [self::TABLE],
        );
        $names = array_map(static fn (array $row): string => (string) $row['TRIGGER_NAME'], $triggers);
        foreach (self::EXPECTED_TRIGGERS as $expected) {
            self::assertContains($expected, $names, $expected.' must exist.');
        }

        foreach ($triggers as $trigger) {
            self::assertSame('BEFORE', $trigger['ACTION_TIMING']);
            $body = strtolower((string) $trigger['ACTION_STATEMENT']);
            self::assertStringNotContainsString('bypass', $body);
            self::assertStringNotContainsString('@testlig', $body);
            self::assertStringNotContainsString('foreign_key_checks', $body);
            self::assertStringNotContainsString('test-only', $body);
            self::assertStringNotContainsString('test_only', $body);
        }
    }

    public function testDeleteTriggerAlwaysSignals(): void
    {
        $body = $this->connection()->fetchOne(
            'SELECT ACTION_STATEMENT
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE()
                AND TRIGGER_NAME = "trg_pwie_bd_deny"',
        );
        self::assertIsString($body);
        self::assertStringContainsString("SIGNAL SQLSTATE '45000'", $body);
    }

    public function testNoColumnStoresRawSignatureOrBody(): void
    {
        $columns = $this->connection()->fetchFirstColumn(
            'SELECT COLUMN_NAME
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?',
            [self::TABLE],
        );
        foreach ($columns as $column) {
            $name = strtolower((string) $column);
            foreach (['raw_body', 'raw_signature', 'idempotency_key', 'card', 'pan', 'cvv'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $name, $column.' must not store sensitive webhook material.');
            }
            self::assertNotSame('signature', $name);
            self::assertNotSame('body', $name);
        }
        self::assertContains('payload_hash', $columns);
        self::assertContains('signature_fingerprint', $columns);
    }

    public function testCheckConstraintsRejectInvalidRows(): void
    {
        $connection = $this->connection();
        $this->expectDatabaseRejection(static function () use ($connection): void {
            $connection->executeStatement(
                'INSERT INTO payment_webhook_inbox_events (
                    id, provider_code, environment, provider_event_reference, event_type,
                    payload_hash, signature_fingerprint, received_at, provider_occurred_at,
                    processing_status, schema_version, sanitized_metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    \Symfony\Component\Uid\Uuid::v7()->toBinary(),
                    '1invalid',
                    'sandbox',
                    'evt_bad_01',
                    'authorized',
                    str_repeat('a', 64),
                    str_repeat('b', 64),
                    '2026-09-13 12:00:00',
                    '2026-09-13 12:00:00',
                    'received',
                    1,
                    '{}',
                ],
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

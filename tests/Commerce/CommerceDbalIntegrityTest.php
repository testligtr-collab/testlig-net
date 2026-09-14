<?php

declare(strict_types=1);

namespace App\Tests\Commerce;

use App\Enum\SecurityAuditAction;
use App\Exception\SecurityAuditMetadataException;
use App\Service\SecurityAuditMetadataSanitizer;
use App\Tests\Support\CommerceDbCleanup;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Schema-level guarantees for the Stage 2.17 commerce tables.
 *
 * These assertions read information_schema instead of trusting the migration source, so a
 * hand-edited database or a drifted migration is caught here.
 */
final class CommerceDbalIntegrityTest extends KernelTestCase
{
    /**
     * @var list<string>
     */
    private const COMMERCE_TABLES = [
        'commercial_offers',
        'commerce_orders',
        'commerce_order_items',
        'payment_attempts',
        'payment_events',
        'commerce_subscriptions',
        'commerce_fulfillments',
        'payment_refunds',
    ];

    /**
     * @var list<string>
     */
    private const EXPECTED_TRIGGERS = [
        'trg_cf_bd_deny',
        'trg_cf_bi_captured',
        'trg_cf_bu_lifecycle',
        'trg_co_bu_immutable',
        'trg_coi_bd_draft_only',
        'trg_coi_bi_draft_only',
        'trg_coi_bu_immutable',
        'trg_cord_bu_lifecycle',
        'trg_cs_bi_offer_recurring',
        'trg_cs_bu_lifecycle',
        'trg_pa_bd_deny',
        'trg_pa_bi_order_state',
        'trg_pa_bu_lifecycle',
        'trg_pe_bd_append_only',
        'trg_pe_bi_chain',
        'trg_pe_bu_append_only',
        'trg_pr_bd_deny',
        'trg_pr_bi_cap',
        'trg_pr_bu_lifecycle',
    ];

    /**
     * @var list<string>
     */
    private const EXPECTED_UNIQUE_INDEXES = [
        'uniq_cf_completed_one_time',
        'uniq_cf_completed_period',
        'uniq_cf_idempotency_key_hash',
        'uniq_cf_license',
        'uniq_cf_order_number',
        'uniq_co_code',
        'uniq_coi_order_offer',
        'uniq_cord_public_reference',
        'uniq_cs_order_offer',
        'uniq_cs_provider_reference',
        'uniq_pa_idempotency_key_hash',
        'uniq_pa_order_attempt_number',
        'uniq_pa_provider_payment_reference',
        'uniq_pe_attempt_sequence',
        'uniq_pe_event_hash',
        'uniq_pe_idempotency_key_hash',
        'uniq_pr_attempt_refund_number',
        'uniq_pr_idempotency_key_hash',
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

    public function testEveryCommerceTableExists(): void
    {
        $schema = $this->connection()->createSchemaManager();
        foreach (self::COMMERCE_TABLES as $table) {
            self::assertTrue($schema->tablesExist([$table]), $table.' must exist');
        }
        CommerceDbCleanup::assertEmpty($this->connection());
    }

    public function testMoneyIsStoredAsIntegerMinorUnitsOnly(): void
    {
        $columns = $this->connection()->fetchAllAssociative(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.$this->tablePlaceholders().')
              ORDER BY TABLE_NAME, COLUMN_NAME',
            self::COMMERCE_TABLES,
        );
        self::assertNotSame([], $columns);

        $moneyColumns = 0;
        foreach ($columns as $column) {
            $dataType = strtolower((string) $column['DATA_TYPE']);
            $name = (string) $column['COLUMN_NAME'];
            self::assertNotContains(
                $dataType,
                ['float', 'double', 'decimal', 'newdecimal', 'real'],
                $column['TABLE_NAME'].'.'.$name.' must not use a floating point or decimal type',
            );
            if (str_ends_with($name, '_amount_minor')) {
                ++$moneyColumns;
                self::assertSame('bigint', $dataType, $name.' must be BIGINT');
            }
            if ('currency' === $name) {
                self::assertSame('char', $dataType);
                self::assertSame('char(3)', strtolower((string) $column['COLUMN_TYPE']));
            }
        }
        self::assertGreaterThanOrEqual(10, $moneyColumns);
    }

    public function testNoCommerceColumnCanHoldCardData(): void
    {
        $columns = $this->connection()->fetchFirstColumn(
            'SELECT CONCAT(TABLE_NAME, ".", COLUMN_NAME)
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.$this->tablePlaceholders().')',
            self::COMMERCE_TABLES,
        );
        foreach ($columns as $column) {
            $name = strtolower((string) $column);
            foreach ([
                'card',
                'pan',
                'cvv',
                'cvc',
                'expiry',
                'expiration',
                'holder',
                'iban',
                'secret',
                'token',
                'raw_key',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $name,
                    $name.' must not look like a card / secret column',
                );
            }
        }
    }

    public function testIdempotencyAndHashColumnsAreFixedWidthDigests(): void
    {
        $columns = $this->connection()->fetchAllAssociative(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.$this->tablePlaceholders().')
                AND (COLUMN_NAME LIKE "%_hash" OR COLUMN_NAME = "idempotency_key_hash")',
            self::COMMERCE_TABLES,
        );
        self::assertNotSame([], $columns);
        foreach ($columns as $column) {
            self::assertSame(
                'varchar(64)',
                strtolower((string) $column['COLUMN_TYPE']),
                $column['TABLE_NAME'].'.'.$column['COLUMN_NAME'].' must be a 64 character digest column',
            );
        }
    }

    public function testAllCommerceTriggersExistAreBeforeAndCarryNoBypass(): void
    {
        $triggers = $this->connection()->fetchAllAssociative(
            'SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_STATEMENT
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN ('.$this->tablePlaceholders().')
              ORDER BY TRIGGER_NAME',
            self::COMMERCE_TABLES,
        );
        $names = array_map(static fn (array $row): string => (string) $row['TRIGGER_NAME'], $triggers);
        foreach (self::EXPECTED_TRIGGERS as $expected) {
            self::assertContains($expected, $names, $expected.' must exist');
        }

        foreach ($triggers as $trigger) {
            self::assertSame('BEFORE', $trigger['ACTION_TIMING']);
            self::assertContains($trigger['EVENT_MANIPULATION'], ['INSERT', 'UPDATE', 'DELETE']);
            $body = strtolower((string) $trigger['ACTION_STATEMENT']);
            self::assertStringNotContainsString('bypass', $body);
            self::assertStringNotContainsString('@testlig', $body);
            self::assertStringNotContainsString('foreign_key_checks', $body);
            self::assertStringNotContainsString('test-only', $body);
            self::assertStringNotContainsString('test_only', $body);
        }
    }

    public function testAppendOnlyAndDenyTriggersAlwaysSignal(): void
    {
        $bodies = $this->connection()->fetchAllKeyValue(
            'SELECT TRIGGER_NAME, ACTION_STATEMENT
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE()
                AND TRIGGER_NAME IN ("trg_pe_bu_append_only", "trg_pe_bd_append_only")',
        );
        self::assertCount(2, $bodies);
        foreach ($bodies as $body) {
            self::assertStringContainsString("SIGNAL SQLSTATE '45000'", (string) $body);
            self::assertStringNotContainsString('IF ', (string) $body);
        }
    }

    public function testDocumentedUniqueIndexesExist(): void
    {
        $indexes = $this->connection()->fetchFirstColumn(
            'SELECT DISTINCT INDEX_NAME
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
                AND TABLE_NAME IN ('.$this->tablePlaceholders().')',
            self::COMMERCE_TABLES,
        );
        foreach (self::EXPECTED_UNIQUE_INDEXES as $expected) {
            self::assertContains($expected, $indexes, $expected.' must exist as a unique index');
        }
    }

    public function testFulfillmentUniquenessIsBackedByGeneratedColumns(): void
    {
        $generated = $this->connection()->fetchAllKeyValue(
            'SELECT COLUMN_NAME, GENERATION_EXPRESSION
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "commerce_fulfillments"
                AND GENERATION_EXPRESSION <> ""',
        );
        self::assertArrayHasKey('completed_one_time_scope', $generated);
        self::assertArrayHasKey('completed_period_scope', $generated);
        foreach ($generated as $expression) {
            self::assertStringContainsString('completed', strtolower((string) $expression));
        }
    }

    public function testEveryCommerceTableIsGuardedByCheckConstraints(): void
    {
        $counts = $this->connection()->fetchAllKeyValue(
            'SELECT tc.TABLE_NAME, COUNT(*)
               FROM information_schema.TABLE_CONSTRAINTS tc
              WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.CONSTRAINT_TYPE = "CHECK"
                AND tc.TABLE_NAME IN ('.$this->tablePlaceholders().')
              GROUP BY tc.TABLE_NAME',
            self::COMMERCE_TABLES,
        );
        foreach (self::COMMERCE_TABLES as $table) {
            self::assertArrayHasKey($table, $counts, $table.' must have CHECK constraints');
            self::assertGreaterThanOrEqual(4, (int) $counts[$table], $table.' needs more than a token CHECK');
        }
    }

    public function testOrderRootedForeignKeysCascadeAndCatalogKeysRestrict(): void
    {
        $rules = $this->connection()->fetchAllKeyValue(
            'SELECT CONSTRAINT_NAME, DELETE_RULE
               FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('.$this->tablePlaceholders().')',
            self::COMMERCE_TABLES,
        );

        foreach ([
            'FK_COI_ORDER',
            'FK_PA_ORDER',
            'FK_PE_ATTEMPT',
            'FK_CS_ORDER',
            'FK_CF_ORDER',
            'FK_PR_ATTEMPT',
        ] as $cascading) {
            self::assertArrayHasKey($cascading, $rules);
            self::assertSame('CASCADE', $rules[$cascading], $cascading.' must cascade from the order root');
        }

        foreach ([
            'FK_CO_PACKAGE',
            'FK_CO_PACKAGE_VERSION',
            'FK_COI_OFFER',
            'FK_CS_OFFER',
            'FK_CF_LICENSE',
        ] as $restricting) {
            self::assertArrayHasKey($restricting, $rules);
            self::assertSame('RESTRICT', $rules[$restricting], $restricting.' must protect catalog rows');
        }
    }

    public function testCompositeForeignKeysPinTheCatalogChain(): void
    {
        $composites = $this->connection()->fetchFirstColumn(
            'SELECT DISTINCT CONSTRAINT_NAME
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
                AND TABLE_NAME IN ('.$this->tablePlaceholders().')',
            self::COMMERCE_TABLES,
        );
        foreach ([
            'FK_CO_PACKAGE_VERSION_PACKAGE',
            'FK_COI_OFFER_PACKAGE',
            'FK_COI_OFFER_PACKAGE_VERSION',
            'FK_COI_ORDER_CURRENCY',
            'FK_PA_ORDER_CURRENCY',
            'FK_CS_OFFER_PACKAGE',
            'FK_CS_ORDER_USER',
            'FK_CS_ORDER_INSTITUTION',
            'FK_CF_ORDER_ITEM_ORDER',
            'FK_CF_ATTEMPT_ORDER',
            'FK_CF_SUBSCRIPTION_ORDER',
        ] as $expected) {
            self::assertContains($expected, $composites, $expected.' must exist as a composite foreign key');
        }
    }

    public function testCommerceAuditActionsAreRegisteredAndSanitizerRefusesRawKeys(): void
    {
        $actions = array_map(
            static fn (SecurityAuditAction $action): string => $action->value,
            SecurityAuditAction::cases(),
        );
        foreach ([
            'commercial_offer_created',
            'commercial_offer_activated',
            'commerce_order_created',
            'commerce_order_paid',
            'payment_attempt_started',
            'payment_captured',
            'payment_refund_requested',
            'commerce_subscription_created',
            'commerce_fulfillment_completed',
            'commerce_fulfillment_reversed',
        ] as $expected) {
            self::assertContains($expected, $actions, $expected.' must be an audit action');
        }

        $sanitizer = static::getContainer()->get(SecurityAuditMetadataSanitizer::class);
        self::assertInstanceOf(SecurityAuditMetadataSanitizer::class, $sanitizer);
        $sanitized = $sanitizer->sanitize([
            'order_id' => '018f0000-0000-7000-8000-000000000000',
            'amount_minor' => 12000,
            'currency' => 'TRY',
        ]);
        $sanitizedKeys = array_keys($sanitized);
        sort($sanitizedKeys);
        self::assertSame(
            ['amount_minor', 'currency', 'order_id'],
            $sanitizedKeys,
        );

        try {
            $sanitizer->sanitize(['idempotency_key_hash' => str_repeat('a', 64)]);
            self::fail('idempotency_key_hash must be refused in audit metadata');
        } catch (SecurityAuditMetadataException $e) {
            self::assertStringContainsString('idempotency_key_hash', $e->getMessage());
        }

        // Anything outside the allowlist is refused loudly rather than silently dropped.
        foreach (['idempotency_key', 'card_number', 'email', 'cvv'] as $forbidden) {
            try {
                $sanitizer->sanitize([$forbidden => 'nope']);
                self::fail('Expected '.$forbidden.' to be refused.');
            } catch (SecurityAuditMetadataException $e) {
                self::assertStringContainsString($forbidden, $e->getMessage());
            }
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function tablePlaceholders(): string
    {
        return implode(', ', array_fill(0, \count(self::COMMERCE_TABLES), '?'));
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}

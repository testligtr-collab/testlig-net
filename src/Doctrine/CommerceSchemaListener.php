<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Mirrors the MariaDB STORED generated columns that enforce fulfillment uniqueness.
 *
 * `completed_one_time_scope` gives one completed fulfillment per one-time order item;
 * `completed_period_scope` gives one completed fulfillment per (subscription, period),
 * so a replayed renewal cannot mint a second license for the same billing window.
 */
final class CommerceSchemaListener
{
    public const ONE_TIME_SCOPE_COLUMN = 'completed_one_time_scope';

    public const ONE_TIME_SCOPE_UNIQUE = 'uniq_cf_completed_one_time';

    public const PERIOD_SCOPE_COLUMN = 'completed_period_scope';

    public const PERIOD_SCOPE_UNIQUE = 'uniq_cf_completed_period';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        if (!$schema->hasTable('commerce_fulfillments')) {
            return;
        }

        $table = $schema->getTable('commerce_fulfillments');
        $this->ensureColumn($table, self::ONE_TIME_SCOPE_COLUMN, 40);
        $this->ensureColumn($table, self::PERIOD_SCOPE_COLUMN, 48);
        $this->ensureUnique($table, self::ONE_TIME_SCOPE_UNIQUE, [self::ONE_TIME_SCOPE_COLUMN]);
        $this->ensureUnique($table, self::PERIOD_SCOPE_UNIQUE, [self::PERIOD_SCOPE_COLUMN]);
    }

    private function ensureColumn(Table $table, string $name, int $length): void
    {
        if ($table->hasColumn($name)) {
            return;
        }

        $table->addColumn($name, Types::STRING, [
            'length' => $length,
            'notnull' => false,
        ]);
    }

    /**
     * @param non-empty-list<string> $columns
     */
    private function ensureUnique(Table $table, string $name, array $columns): void
    {
        if ($table->hasIndex($name)) {
            return;
        }

        foreach ($table->getIndexes() as $index) {
            if ($index->isUnique() && $columns === $index->getColumns()) {
                return;
            }
        }

        $table->addUniqueIndex($columns, $name);
    }
}

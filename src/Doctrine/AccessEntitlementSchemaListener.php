<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Mirrors MariaDB STORED generated catalog_scope_key for Doctrine schema sync.
 */
final class AccessEntitlementSchemaListener
{
    public const CATALOG_SCOPE_COLUMN = 'catalog_scope_key';

    public const CATALOG_SCOPE_UNIQUE = 'uniq_apcg_version_scope';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        if (!$schema->hasTable('access_package_catalog_grants')) {
            return;
        }

        $table = $schema->getTable('access_package_catalog_grants');
        if (!$table->hasColumn(self::CATALOG_SCOPE_COLUMN)) {
            $table->addColumn(self::CATALOG_SCOPE_COLUMN, Types::STRING, [
                'length' => 128,
                'notnull' => false,
            ]);
        }
        $this->ensureUnique($table, self::CATALOG_SCOPE_UNIQUE, ['version_id', self::CATALOG_SCOPE_COLUMN]);
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

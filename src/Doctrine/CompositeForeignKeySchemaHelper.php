<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Schema\Table;

/**
 * Shared helper for composite foreign keys that Doctrine associations cannot express.
 */
final class CompositeForeignKeySchemaHelper
{
    /**
     * @param non-empty-list<string> $localColumns
     * @param non-empty-list<string> $foreignColumns
     * @param array<string, mixed>   $options
     */
    public static function ensureForeignKey(
        Table $table,
        string $name,
        string $foreignTable,
        array $localColumns,
        array $foreignColumns,
        array $options = ['onDelete' => 'CASCADE'],
    ): void {
        foreach ($table->getForeignKeys() as $existing) {
            if ($existing->getName() === $name) {
                return;
            }
            if ($existing->getLocalColumns() === $localColumns && $existing->getForeignTableName() === $foreignTable) {
                return;
            }
        }

        $table->addForeignKeyConstraint(
            $foreignTable,
            $localColumns,
            $foreignColumns,
            $options,
            $name,
        );
    }
}

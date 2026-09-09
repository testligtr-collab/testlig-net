<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Curriculum\CurriculumTopicPositionScope;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Keeps Doctrine schema in sync with MariaDB curriculum_topics.position_scope_id.
 *
 * The STORED GENERATED expression lives in Version20260909120000. MariaDB
 * introspection does not expose columnDefinition, so this listener mirrors the
 * introspected BINARY(16) shape + UNIQUE(unit_id, position_scope_id, position)
 * without rewriting the generation expression on every schema:update.
 */
final class CurriculumTopicPositionScopeSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        if (!$schema->hasTable('curriculum_topics')) {
            return;
        }

        $table = $schema->getTable('curriculum_topics');
        $this->ensureScopeColumn($table);
        $this->dropLegacyParentPositionUnique($table);
        $this->ensureScopePositionUnique($table);
    }

    private function ensureScopeColumn(Table $table): void
    {
        if ($table->hasColumn(CurriculumTopicPositionScope::COLUMN_NAME)) {
            return;
        }

        // Match MariaDB DBAL introspection of a STORED generated BINARY(16) column.
        $table->addColumn(CurriculumTopicPositionScope::COLUMN_NAME, Types::BINARY, [
            'length' => 16,
            'fixed' => true,
            'notnull' => false,
        ]);
    }

    private function dropLegacyParentPositionUnique(Table $table): void
    {
        foreach ($table->getIndexes() as $index) {
            if (!$index->isUnique() || $index->isPrimary()) {
                continue;
            }
            $columns = $index->getColumns();
            if (['unit_id', 'parent_id', 'position'] === $columns
                || 'uniq_curriculum_topic_unit_parent_position' === $index->getName()) {
                $table->dropIndex($index->getName());
            }
        }
    }

    private function ensureScopePositionUnique(Table $table): void
    {
        $name = CurriculumTopicPositionScope::UNIQUE_INDEX_NAME;
        if ($table->hasIndex($name)) {
            return;
        }

        foreach ($table->getIndexes() as $index) {
            if ($index->isUnique()
                && ['unit_id', CurriculumTopicPositionScope::COLUMN_NAME, 'position'] === $index->getColumns()) {
                return;
            }
        }

        $table->addUniqueIndex(
            ['unit_id', CurriculumTopicPositionScope::COLUMN_NAME, 'position'],
            $name,
        );
    }
}

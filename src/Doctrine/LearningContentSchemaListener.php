<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\LearningContent\LearningContentPlatformSlugScope;
use App\LearningContent\LearningContentRevisionPrimaryAlignmentScope;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Mirrors MariaDB STORED generated columns for Doctrine schema sync.
 */
final class LearningContentSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('learning_contents')) {
            $table = $schema->getTable('learning_contents');
            $this->ensureBinaryNullableColumn($table, LearningContentPlatformSlugScope::COLUMN_NAME, 200, false);
            $this->ensureUnique($table, LearningContentPlatformSlugScope::UNIQUE_INDEX_NAME, [
                LearningContentPlatformSlugScope::COLUMN_NAME,
            ]);
        }

        if ($schema->hasTable('learning_content_outcome_alignments')) {
            $table = $schema->getTable('learning_content_outcome_alignments');
            $this->ensureBinaryNullableColumn(
                $table,
                LearningContentRevisionPrimaryAlignmentScope::COLUMN_NAME,
                16,
                true,
            );
            $this->ensureUnique($table, LearningContentRevisionPrimaryAlignmentScope::UNIQUE_INDEX_NAME, [
                LearningContentRevisionPrimaryAlignmentScope::COLUMN_NAME,
            ]);
        }
    }

    private function ensureBinaryNullableColumn(Table $table, string $name, int $length, bool $fixed): void
    {
        if ($table->hasColumn($name)) {
            return;
        }

        if ($fixed) {
            $table->addColumn($name, Types::BINARY, [
                'length' => $length,
                'fixed' => true,
                'notnull' => false,
            ]);

            return;
        }

        $table->addColumn($name, Types::STRING, [
            'length' => $length,
            'notnull' => false,
        ]);
    }

    /**
     * @param list<string> $columns
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

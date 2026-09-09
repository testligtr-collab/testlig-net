<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Question\QuestionRevisionPrimaryAlignmentScope;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Mirrors MariaDB STORED generated primary_revision_scope_id for Doctrine schema sync.
 */
final class QuestionRevisionPrimaryAlignmentScopeSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        if (!$schema->hasTable('question_revision_alignments')) {
            return;
        }

        $table = $schema->getTable('question_revision_alignments');
        $this->ensureScopeColumn($table);
        $this->ensureScopeUnique($table);
    }

    private function ensureScopeColumn(Table $table): void
    {
        if ($table->hasColumn(QuestionRevisionPrimaryAlignmentScope::COLUMN_NAME)) {
            return;
        }

        $table->addColumn(QuestionRevisionPrimaryAlignmentScope::COLUMN_NAME, Types::BINARY, [
            'length' => 16,
            'fixed' => true,
            'notnull' => false,
        ]);
    }

    private function ensureScopeUnique(Table $table): void
    {
        $name = QuestionRevisionPrimaryAlignmentScope::UNIQUE_INDEX_NAME;
        if ($table->hasIndex($name)) {
            return;
        }

        foreach ($table->getIndexes() as $index) {
            if ($index->isUnique()
                && [QuestionRevisionPrimaryAlignmentScope::COLUMN_NAME] === $index->getColumns()) {
                return;
            }
        }

        $table->addUniqueIndex(
            [QuestionRevisionPrimaryAlignmentScope::COLUMN_NAME],
            $name,
        );
    }
}

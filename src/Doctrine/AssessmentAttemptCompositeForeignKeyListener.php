<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Composite FKs for assessment attempt / item / answer / active-guard consistency.
 *
 * Also mirrors MariaDB STORED generated active_recipient_scope_id so dump-sql stays empty
 * (introspection does not expose GENERATED ALWAYS expressions).
 */
final class AssessmentAttemptCompositeForeignKeyListener
{
    private const ACTIVE_SCOPE_COLUMN = 'active_recipient_scope_id';

    private const ACTIVE_SCOPE_UNIQUE = 'uniq_aa_active_recipient_scope';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('assessment_items')) {
            $table = $schema->getTable('assessment_items');
            if (!$table->hasIndex('uniq_assessment_item_id_question_revision')) {
                $table->addUniqueIndex(
                    ['id', 'question_id', 'question_revision_id'],
                    'uniq_assessment_item_id_question_revision',
                );
            }
        }

        if ($schema->hasTable('assessment_delivery_recipients')) {
            $table = $schema->getTable('assessment_delivery_recipients');
            if (!$table->hasIndex('uniq_adr_delivery_id')) {
                $table->addUniqueIndex(
                    ['delivery_id', 'id'],
                    'uniq_adr_delivery_id',
                );
            }
        }

        if ($schema->hasTable('assessment_attempts')) {
            $table = $schema->getTable('assessment_attempts');
            $this->ensureActiveRecipientScopeColumn($table);
            $this->ensureUniqueIndex($table, self::ACTIVE_SCOPE_UNIQUE, [self::ACTIVE_SCOPE_COLUMN]);
            $this->ensureUniqueIndex($table, 'uniq_aa_id_revision', ['id', 'assessment_revision_id']);
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AA_DELIVERY_INSTITUTION',
                'assessment_deliveries',
                ['delivery_id', 'institution_id'],
                ['id', 'institution_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AA_DELIVERY_RECIPIENT',
                'assessment_delivery_recipients',
                ['delivery_id', 'recipient_id'],
                ['delivery_id', 'id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AA_PUBLICATION_ASSESSMENT_NUMBER',
                'assessment_publications',
                ['assessment_publication_id', 'assessment_id', 'publication_number'],
                ['id', 'assessment_id', 'publication_number'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AA_PUBLICATION_REVISION',
                'assessment_publications',
                ['assessment_publication_id', 'assessment_revision_id'],
                ['id', 'assessment_revision_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AA_MEMBERSHIP_INSTITUTION_USER',
                'institution_memberships',
                ['student_membership_id', 'institution_id', 'user_id'],
                ['id', 'institution_id', 'user_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('assessment_attempt_active_guards')) {
            $table = $schema->getTable('assessment_attempt_active_guards');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AAAG_ATTEMPT_DELIVERY_RECIPIENT',
                'assessment_attempts',
                ['attempt_id', 'delivery_id', 'recipient_id'],
                ['id', 'delivery_id', 'recipient_id'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('assessment_attempt_items')) {
            $table = $schema->getTable('assessment_attempt_items');
            $this->ensureUniqueIndex($table, 'uniq_aai_id_attempt_revision', ['id', 'attempt_id', 'assessment_revision_id']);
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AAI_ITEM_QUESTION_REVISION',
                'assessment_items',
                ['assessment_item_id', 'question_id', 'question_revision_id'],
                ['id', 'question_id', 'question_revision_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AAI_ATTEMPT_REVISION',
                'assessment_attempts',
                ['attempt_id', 'assessment_revision_id'],
                ['id', 'assessment_revision_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AAI_SECTION_REVISION',
                'assessment_sections',
                ['assessment_section_id', 'assessment_revision_id'],
                ['id', 'revision_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AAI_ITEM_SECTION',
                'assessment_items',
                ['assessment_item_id', 'assessment_section_id'],
                ['id', 'section_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('assessment_attempt_answers')) {
            $table = $schema->getTable('assessment_attempt_answers');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AAA_ITEM_ATTEMPT',
                'assessment_attempt_items',
                ['attempt_item_id', 'attempt_id'],
                ['id', 'attempt_id'],
                ['onDelete' => 'CASCADE'],
            );
        }
    }

    private function ensureActiveRecipientScopeColumn(Table $table): void
    {
        if ($table->hasColumn(self::ACTIVE_SCOPE_COLUMN)) {
            return;
        }

        // Match MariaDB DBAL introspection of a STORED generated BINARY(16) column.
        $table->addColumn(self::ACTIVE_SCOPE_COLUMN, Types::BINARY, [
            'length' => 16,
            'fixed' => true,
            'notnull' => false,
        ]);
    }

    /**
     * @param non-empty-list<string> $columns
     */
    private function ensureUniqueIndex(Table $table, string $name, array $columns): void
    {
        if ($table->hasIndex($name)) {
            return;
        }

        foreach ($table->getIndexes() as $index) {
            if ($index->isUnique() && $index->getColumns() === $columns) {
                return;
            }
        }

        $table->addUniqueIndex($columns, $name);
    }
}

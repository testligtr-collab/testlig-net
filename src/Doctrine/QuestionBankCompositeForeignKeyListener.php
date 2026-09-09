<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Composite FKs for question bank + learning outcome hierarchy consistency.
 */
final class QuestionBankCompositeForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('curriculum_learning_outcomes')) {
            $table = $schema->getTable('curriculum_learning_outcomes');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CLO_TOPIC_UNIT',
                'curriculum_topics',
                ['topic_id', 'unit_id'],
                ['id', 'unit_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CLO_UNIT_PROGRAM',
                'curriculum_units',
                ['unit_id', 'curriculum_program_id'],
                ['id', 'curriculum_program_id'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('question_revision_alignments')) {
            $table = $schema->getTable('question_revision_alignments');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_QRA_PROGRAM_SUBJECT',
                'curriculum_programs',
                ['curriculum_program_id', 'subject_id'],
                ['id', 'subject_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_QRA_OUTCOME_TOPIC_PROGRAM',
                'curriculum_learning_outcomes',
                ['learning_outcome_id', 'curriculum_topic_id', 'curriculum_program_id'],
                ['id', 'topic_id', 'curriculum_program_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('question_revision_primary_alignment_guards')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('question_revision_primary_alignment_guards'),
                'FK_QRPAG_ALIGNMENT_REVISION_PRIMARY',
                'question_revision_alignments',
                ['alignment_id', 'revision_id', 'must_be_primary'],
                ['id', 'revision_id', 'is_primary'],
                ['onDelete' => 'CASCADE'],
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Composite FKs for learning content hierarchy and primary alignment guard.
 */
final class LearningContentCompositeForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('learning_contents')) {
            $table = $schema->getTable('learning_contents');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_LC_PUBLISHED_REVISION_CONTENT',
                'learning_content_revisions',
                ['published_revision_id', 'id', 'published_revision_number'],
                ['id', 'content_id', 'revision_number'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('learning_content_publications')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('learning_content_publications'),
                'FK_LCP_REVISION_CONTENT',
                'learning_content_revisions',
                ['revision_id', 'content_id'],
                ['id', 'content_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('learning_content_outcome_alignments')) {
            $table = $schema->getTable('learning_content_outcome_alignments');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_LCOA_PROGRAM_SUBJECT',
                'curriculum_programs',
                ['curriculum_program_id', 'subject_id'],
                ['id', 'subject_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_LCOA_OUTCOME_TOPIC_PROGRAM',
                'curriculum_learning_outcomes',
                ['learning_outcome_id', 'curriculum_topic_id', 'curriculum_program_id'],
                ['id', 'topic_id', 'curriculum_program_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('learning_content_revision_primary_alignment_guards')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('learning_content_revision_primary_alignment_guards'),
                'FK_LCRPAG_ALIGNMENT_REVISION_PRIMARY',
                'learning_content_outcome_alignments',
                ['alignment_id', 'revision_id', 'must_be_primary'],
                ['id', 'revision_id', 'is_primary'],
                ['onDelete' => 'CASCADE'],
            );
        }
    }
}

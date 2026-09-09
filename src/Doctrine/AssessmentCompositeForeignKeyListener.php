<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Composite FKs for assessment revision graph consistency.
 */
final class AssessmentCompositeForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('assessment_items')) {
            $table = $schema->getTable('assessment_items');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AI_SECTION_REVISION',
                'assessment_sections',
                ['section_id', 'assessment_revision_id'],
                ['id', 'revision_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AI_QUESTION_REVISION_CHAIN',
                'question_revisions',
                ['question_revision_id', 'question_id'],
                ['id', 'question_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('assessment_publications')) {
            $table = $schema->getTable('assessment_publications');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AP_REVISION_ASSESSMENT',
                'assessment_revisions',
                ['assessment_revision_id', 'assessment_id'],
                ['id', 'assessment_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }
    }
}

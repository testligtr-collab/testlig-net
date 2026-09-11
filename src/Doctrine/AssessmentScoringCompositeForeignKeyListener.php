<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Composite FKs for assessment scoring / item scores / manual decisions / result releases.
 */
final class AssessmentScoringCompositeForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('assessment_attempt_items')) {
            $table = $schema->getTable('assessment_attempt_items');
            if (!$table->hasIndex('uniq_aai_id_question_revision')) {
                $table->addUniqueIndex(
                    ['id', 'question_id', 'question_revision_id'],
                    'uniq_aai_id_question_revision',
                );
            }
        }

        if ($schema->hasTable('assessment_scoring_runs')) {
            $table = $schema->getTable('assessment_scoring_runs');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ASR_ATTEMPT_INSTITUTION',
                'assessment_attempts',
                ['attempt_id', 'institution_id'],
                ['id', 'institution_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ASR_ATTEMPT_DELIVERY',
                'assessment_attempts',
                ['attempt_id', 'delivery_id'],
                ['id', 'delivery_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ASR_ATTEMPT_RECIPIENT',
                'assessment_attempts',
                ['attempt_id', 'recipient_id'],
                ['id', 'recipient_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ASR_ATTEMPT_USER',
                'assessment_attempts',
                ['attempt_id', 'user_id'],
                ['id', 'user_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ASR_ATTEMPT_ASSESSMENT',
                'assessment_attempts',
                ['attempt_id', 'assessment_id'],
                ['id', 'assessment_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ASR_ATTEMPT_PUBLICATION',
                'assessment_attempts',
                ['attempt_id', 'assessment_publication_id'],
                ['id', 'assessment_publication_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ASR_ATTEMPT_REVISION',
                'assessment_attempts',
                ['attempt_id', 'assessment_revision_id'],
                ['id', 'assessment_revision_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ASR_ATTEMPT_DELIVERY_RECIPIENT',
                'assessment_attempts',
                ['attempt_id', 'delivery_id', 'recipient_id'],
                ['id', 'delivery_id', 'recipient_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ASR_PUBLICATION_ASSESSMENT_NUMBER',
                'assessment_publications',
                ['assessment_publication_id', 'assessment_id', 'publication_number'],
                ['id', 'assessment_id', 'publication_number'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ASR_PUBLICATION_REVISION',
                'assessment_publications',
                ['assessment_publication_id', 'assessment_revision_id'],
                ['id', 'assessment_revision_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('assessment_item_scores')) {
            $table = $schema->getTable('assessment_item_scores');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AIS_RUN_ATTEMPT',
                'assessment_scoring_runs',
                ['scoring_run_id', 'attempt_id'],
                ['id', 'attempt_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AIS_RUN_ATTEMPT_REVISION',
                'assessment_scoring_runs',
                ['scoring_run_id', 'attempt_id', 'assessment_revision_id'],
                ['id', 'attempt_id', 'assessment_revision_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AIS_ITEM_ATTEMPT',
                'assessment_attempt_items',
                ['attempt_item_id', 'attempt_id'],
                ['id', 'attempt_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AIS_ITEM_ATTEMPT_REVISION',
                'assessment_attempt_items',
                ['attempt_item_id', 'attempt_id', 'assessment_revision_id'],
                ['id', 'attempt_id', 'assessment_revision_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AIS_ITEM_QUESTION_REVISION',
                'assessment_attempt_items',
                ['attempt_item_id', 'question_id', 'question_revision_id'],
                ['id', 'question_id', 'question_revision_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('assessment_manual_grade_decisions')) {
            $table = $schema->getTable('assessment_manual_grade_decisions');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AMGD_RUN_ATTEMPT',
                'assessment_scoring_runs',
                ['scoring_run_id', 'attempt_id'],
                ['id', 'attempt_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AMGD_ITEM_ATTEMPT',
                'assessment_attempt_items',
                ['attempt_item_id', 'attempt_id'],
                ['id', 'attempt_id'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('assessment_result_releases')) {
            $table = $schema->getTable('assessment_result_releases');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ARR_RUN_ATTEMPT',
                'assessment_scoring_runs',
                ['scoring_run_id', 'attempt_id'],
                ['id', 'attempt_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('assessment_result_active_release_guards')) {
            $table = $schema->getTable('assessment_result_active_release_guards');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ARARG_RELEASE_ATTEMPT',
                'assessment_result_releases',
                ['release_id', 'attempt_id'],
                ['id', 'attempt_id'],
                ['onDelete' => 'CASCADE'],
            );
        }
    }
}

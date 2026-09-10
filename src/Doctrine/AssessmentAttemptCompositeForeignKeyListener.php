<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Composite FKs for assessment attempt / item / answer / active-guard consistency.
 */
final class AssessmentAttemptCompositeForeignKeyListener
{
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
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AAI_ITEM_QUESTION_REVISION',
                'assessment_items',
                ['assessment_item_id', 'question_id', 'question_revision_id'],
                ['id', 'question_id', 'question_revision_id'],
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
}

<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Composite FKs for assessment delivery / recipient consistency.
 */
final class AssessmentDeliveryCompositeForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('assessment_publications')) {
            $table = $schema->getTable('assessment_publications');
            if (!$table->hasIndex('uniq_ap_id_assessment_number')) {
                $table->addUniqueIndex(
                    ['id', 'assessment_id', 'publication_number'],
                    'uniq_ap_id_assessment_number',
                );
            }
        }

        if ($schema->hasTable('institution_memberships')) {
            $table = $schema->getTable('institution_memberships');
            if (!$table->hasIndex('uniq_membership_id_institution_user')) {
                $table->addUniqueIndex(
                    ['id', 'institution_id', 'user_id'],
                    'uniq_membership_id_institution_user',
                );
            }
        }

        if ($schema->hasTable('classroom_student_enrollments')) {
            $table = $schema->getTable('classroom_student_enrollments');
            if (!$table->hasIndex('uniq_cse_id_classroom_membership')) {
                $table->addUniqueIndex(
                    ['id', 'classroom_id', 'student_membership_id'],
                    'uniq_cse_id_classroom_membership',
                );
            }
        }

        if ($schema->hasTable('assessment_deliveries')) {
            $table = $schema->getTable('assessment_deliveries');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AD_PUBLICATION_ASSESSMENT_NUMBER',
                'assessment_publications',
                ['assessment_publication_id', 'assessment_id', 'publication_number'],
                ['id', 'assessment_id', 'publication_number'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AD_CLASSROOM_INSTITUTION',
                'classrooms',
                ['classroom_id', 'institution_id'],
                ['id', 'institution_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AD_MEMBERSHIP_INSTITUTION',
                'institution_memberships',
                ['student_membership_id', 'institution_id'],
                ['id', 'institution_id'],
                ['onDelete' => 'RESTRICT'],
            );
            // Platform assessments have NULL institution_id; tenant match for institution-scoped
            // assessments is enforced in AssessmentDeliveryManager (not a composite FK).
        }

        if ($schema->hasTable('assessment_delivery_recipients')) {
            $table = $schema->getTable('assessment_delivery_recipients');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ADR_DELIVERY_INSTITUTION',
                'assessment_deliveries',
                ['delivery_id', 'institution_id'],
                ['id', 'institution_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ADR_MEMBERSHIP_INSTITUTION_USER',
                'institution_memberships',
                ['student_membership_id', 'institution_id', 'user_id'],
                ['id', 'institution_id', 'user_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ADR_SOURCE_CLASSROOM_INSTITUTION',
                'classrooms',
                ['source_classroom_id', 'institution_id'],
                ['id', 'institution_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ADR_SOURCE_ENROLLMENT_CLASSROOM_MEMBERSHIP',
                'classroom_student_enrollments',
                ['source_enrollment_id', 'source_classroom_id', 'student_membership_id'],
                ['id', 'classroom_id', 'student_membership_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }
    }
}

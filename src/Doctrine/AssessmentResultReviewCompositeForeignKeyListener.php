<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Composite FKs for assessment result review policies / active guards.
 */
final class AssessmentResultReviewCompositeForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('assessment_result_review_policies')) {
            $table = $schema->getTable('assessment_result_review_policies');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ARRP_DELIVERY_INSTITUTION',
                'assessment_deliveries',
                ['delivery_id', 'institution_id'],
                ['id', 'institution_id'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('assessment_result_active_review_policy_guards')) {
            $table = $schema->getTable('assessment_result_active_review_policy_guards');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ARARPG_POLICY_DELIVERY',
                'assessment_result_review_policies',
                ['policy_id', 'delivery_id'],
                ['id', 'delivery_id'],
                ['onDelete' => 'CASCADE'],
            );
        }
    }
}

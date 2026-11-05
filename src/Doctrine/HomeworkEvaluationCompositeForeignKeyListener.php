<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Composite FKs that keep evaluation supersede chains and the submission current pointer
 * on the same submission. Doctrine associations cannot express these; migrations create them
 * and this listener keeps doctrine:schema:validate in sync.
 */
final class HomeworkEvaluationCompositeForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('homework_evaluations')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('homework_evaluations'),
                'FK_HW_EVAL_SUPERSEDES_SAME',
                'homework_evaluations',
                ['supersedes_evaluation_id', 'submission_id'],
                ['id', 'submission_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('homework_submissions')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('homework_submissions'),
                'FK_HW_SUB_CURRENT_EVAL_SAME',
                'homework_evaluations',
                ['current_evaluation_id', 'id'],
                ['id', 'submission_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }
    }
}

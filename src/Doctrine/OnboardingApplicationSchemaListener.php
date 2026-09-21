<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Onboarding\OnboardingPendingOwnerScope;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Mirrors MariaDB STORED pending_owner_id so schema:update --dump-sql stays empty.
 *
 * Introspection does not expose GENERATED ALWAYS expressions; the listener only
 * mirrors the BINARY(16) nullable shape + UNIQUE(pending_owner_id).
 */
final class OnboardingApplicationSchemaListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable(OnboardingPendingOwnerScope::TEACHER_TABLE)) {
            $this->ensurePendingOwner(
                $schema->getTable(OnboardingPendingOwnerScope::TEACHER_TABLE),
                OnboardingPendingOwnerScope::TEACHER_UNIQUE_INDEX,
            );
        }

        if ($schema->hasTable(OnboardingPendingOwnerScope::INSTITUTION_TABLE)) {
            $this->ensurePendingOwner(
                $schema->getTable(OnboardingPendingOwnerScope::INSTITUTION_TABLE),
                OnboardingPendingOwnerScope::INSTITUTION_UNIQUE_INDEX,
            );
        }
    }

    private function ensurePendingOwner(Table $table, string $uniqueIndex): void
    {
        if (!$table->hasColumn(OnboardingPendingOwnerScope::COLUMN_NAME)) {
            $table->addColumn(OnboardingPendingOwnerScope::COLUMN_NAME, Types::BINARY, [
                'length' => 16,
                'fixed' => true,
                'notnull' => false,
            ]);
        }

        if ($table->hasIndex($uniqueIndex)) {
            return;
        }

        foreach ($table->getIndexes() as $index) {
            if ($index->isUnique()
                && [OnboardingPendingOwnerScope::COLUMN_NAME] === $index->getColumns()) {
                return;
            }
        }

        $table->addUniqueIndex([OnboardingPendingOwnerScope::COLUMN_NAME], $uniqueIndex);
    }
}

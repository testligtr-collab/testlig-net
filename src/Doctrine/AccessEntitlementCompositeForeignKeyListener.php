<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Composite FKs for access package / license / seat tables.
 */
final class AccessEntitlementCompositeForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('access_package_versions')) {
            $table = $schema->getTable('access_package_versions');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_APV_ID_PACKAGE',
                'access_packages',
                ['package_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('access_package_active_version_guards')) {
            $table = $schema->getTable('access_package_active_version_guards');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_APAVG_VERSION_PACKAGE',
                'access_package_versions',
                ['version_id', 'package_id'],
                ['id', 'package_id'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('access_package_learning_content_grants')) {
            $table = $schema->getTable('access_package_learning_content_grants');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_APLCG_ID_VERSION',
                'access_package_versions',
                ['version_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('access_package_assessment_grants')) {
            $table = $schema->getTable('access_package_assessment_grants');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_APAG_ID_VERSION',
                'access_package_versions',
                ['version_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('access_package_catalog_grants')) {
            $table = $schema->getTable('access_package_catalog_grants');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_APCG_ID_VERSION',
                'access_package_versions',
                ['version_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('access_licenses')) {
            $table = $schema->getTable('access_licenses');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_AL_PACKAGE_VERSION_PACKAGE',
                'access_package_versions',
                ['package_version_id', 'package_id'],
                ['id', 'package_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('institution_license_seats')) {
            $table = $schema->getTable('institution_license_seats');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ILS_LICENSE_INSTITUTION',
                'access_licenses',
                ['license_id', 'institution_id'],
                ['id', 'institution_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ILS_MEMBERSHIP_INSTITUTION_USER',
                'institution_memberships',
                ['membership_id', 'institution_id', 'user_id'],
                ['id', 'institution_id', 'user_id'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('institution_license_active_seat_guards')) {
            $table = $schema->getTable('institution_license_active_seat_guards');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_ILASG_SEAT_LICENSE_MEMBERSHIP',
                'institution_license_seats',
                ['seat_id', 'license_id', 'membership_id'],
                ['id', 'license_id', 'membership_id'],
                ['onDelete' => 'CASCADE'],
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Composite FKs for the commerce catalog, order, payment, and fulfillment tables.
 *
 * These pairs are what stop a row from silently pointing at a different tenant, package,
 * currency, or order than the one it was priced against — Doctrine associations can only
 * express the single-column half.
 */
final class CommerceCompositeForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('commercial_offers')) {
            $table = $schema->getTable('commercial_offers');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CO_PACKAGE_VERSION_PACKAGE',
                'access_package_versions',
                ['package_version_id', 'package_id'],
                ['id', 'package_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('commerce_order_items')) {
            $table = $schema->getTable('commerce_order_items');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_COI_OFFER_PACKAGE',
                'commercial_offers',
                ['offer_id', 'package_id'],
                ['id', 'package_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_COI_OFFER_PACKAGE_VERSION',
                'commercial_offers',
                ['offer_id', 'package_version_id'],
                ['id', 'package_version_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_COI_ORDER_CURRENCY',
                'commerce_orders',
                ['order_id', 'currency'],
                ['id', 'currency'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('payment_attempts')) {
            $table = $schema->getTable('payment_attempts');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_PA_ORDER_CURRENCY',
                'commerce_orders',
                ['order_id', 'currency'],
                ['id', 'currency'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('commerce_subscriptions')) {
            $table = $schema->getTable('commerce_subscriptions');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CS_OFFER_PACKAGE',
                'commercial_offers',
                ['offer_id', 'package_id'],
                ['id', 'package_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CS_OFFER_PACKAGE_VERSION',
                'commercial_offers',
                ['offer_id', 'package_version_id'],
                ['id', 'package_version_id'],
                ['onDelete' => 'RESTRICT'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CS_ORDER_USER',
                'commerce_orders',
                ['order_id', 'user_id'],
                ['id', 'user_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CS_ORDER_INSTITUTION',
                'commerce_orders',
                ['order_id', 'institution_id'],
                ['id', 'institution_id'],
                ['onDelete' => 'CASCADE'],
            );
        }

        if ($schema->hasTable('commerce_fulfillments')) {
            $table = $schema->getTable('commerce_fulfillments');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CF_ORDER_ITEM_ORDER',
                'commerce_order_items',
                ['order_item_id', 'order_id'],
                ['id', 'order_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CF_ATTEMPT_ORDER',
                'payment_attempts',
                ['payment_attempt_id', 'order_id'],
                ['id', 'order_id'],
                ['onDelete' => 'CASCADE'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CF_SUBSCRIPTION_ORDER',
                'commerce_subscriptions',
                ['subscription_id', 'order_id'],
                ['id', 'order_id'],
                ['onDelete' => 'CASCADE'],
            );
        }
    }
}

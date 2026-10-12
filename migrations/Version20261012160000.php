<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records the PDF storage provider on each asset.
 * Existing rows stay local. This migration does not move bytes.
 */
final class Version20261012160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add learning_document_assets.storage_provider and keep existing rows local.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE learning_document_assets ADD storage_provider VARCHAR(16) DEFAULT 'local' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE learning_document_assets DROP storage_provider');
    }
}

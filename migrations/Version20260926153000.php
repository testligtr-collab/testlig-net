<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PDF metadata for learning documents. down() drops the table only.
 * It does not delete files under var/learning-documents.
 */
final class Version20260926153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add learning_document_assets for pending PDF documents stored outside the web root.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE learning_document_assets (
                id BINARY(16) NOT NULL,
                media_type VARCHAR(32) NOT NULL,
                created_by_id BINARY(16) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(127) NOT NULL,
                byte_size INT NOT NULL,
                storage_key VARCHAR(64) NOT NULL,
                content_sha256 VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL,
                ready_at DATETIME DEFAULT NULL,
                quarantined_at DATETIME DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_learning_document_storage_key (storage_key),
                INDEX idx_learning_document_created_by_status (created_by_id, status),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE learning_document_assets ADD CONSTRAINT FK_BD41B1CBB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE learning_document_assets DROP FOREIGN KEY FK_BD41B1CBB03A8386');
        $this->addSql('DROP TABLE learning_document_assets');
    }
}

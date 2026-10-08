<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CatalogTopicAssessment placement table (navigation only).
 */
final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add catalog_topic_assessments placement table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_topic_assessments (
            id BINARY(16) NOT NULL,
            catalog_topic_id BINARY(16) NOT NULL,
            assessment_id BINARY(16) NOT NULL,
            slug VARCHAR(180) NOT NULL,
            display_title VARCHAR(200) NOT NULL,
            summary LONGTEXT DEFAULT NULL,
            position INT NOT NULL,
            visibility_status VARCHAR(32) NOT NULL,
            published_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            created_by_id BINARY(16) DEFAULT NULL,
            INDEX idx_cta_topic_visibility_pos (catalog_topic_id, visibility_status, position),
            INDEX idx_cta_assessment (assessment_id),
            INDEX idx_cta_created_by (created_by_id),
            UNIQUE INDEX uniq_cta_topic_slug (catalog_topic_id, slug),
            UNIQUE INDEX uniq_cta_topic_position (catalog_topic_id, position),
            UNIQUE INDEX uniq_cta_topic_assessment (catalog_topic_id, assessment_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE catalog_topic_assessments ADD CONSTRAINT FK_CTA_CATALOG_TOPIC FOREIGN KEY (catalog_topic_id) REFERENCES catalog_topics (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE catalog_topic_assessments ADD CONSTRAINT FK_CTA_ASSESSMENT FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE catalog_topic_assessments ADD CONSTRAINT FK_CTA_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_topic_assessments DROP FOREIGN KEY FK_CTA_CATALOG_TOPIC');
        $this->addSql('ALTER TABLE catalog_topic_assessments DROP FOREIGN KEY FK_CTA_ASSESSMENT');
        $this->addSql('ALTER TABLE catalog_topic_assessments DROP FOREIGN KEY FK_CTA_CREATED_BY');
        $this->addSql('DROP TABLE catalog_topic_assessments');
    }
}

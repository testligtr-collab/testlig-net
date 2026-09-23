<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CatalogSubject → canonical Subject mapping + CatalogTopicLesson placement table.
 *
 * Catalog and Curriculum Subject remain separate entities; mapping is explicit UUID FK only.
 */
final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add catalog_subjects.canonical_subject_id and catalog_topic_lessons placement table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_subjects ADD canonical_subject_id BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_catalog_subject_canonical_subject ON catalog_subjects (canonical_subject_id)');
        $this->addSql('ALTER TABLE catalog_subjects ADD CONSTRAINT FK_CATALOG_SUBJECT_CANONICAL_SUBJECT FOREIGN KEY (canonical_subject_id) REFERENCES subjects (id) ON DELETE RESTRICT');

        $this->addSql('CREATE TABLE catalog_topic_lessons (
            id BINARY(16) NOT NULL,
            catalog_topic_id BINARY(16) NOT NULL,
            learning_content_id BINARY(16) NOT NULL,
            slug VARCHAR(180) NOT NULL,
            display_title VARCHAR(200) NOT NULL,
            summary LONGTEXT DEFAULT NULL,
            position INT NOT NULL,
            visibility_status VARCHAR(32) NOT NULL,
            published_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            created_by_id BINARY(16) DEFAULT NULL,
            INDEX idx_ctl_topic_visibility_pos (catalog_topic_id, visibility_status, position),
            INDEX idx_ctl_learning_content (learning_content_id),
            INDEX idx_ctl_created_by (created_by_id),
            UNIQUE INDEX uniq_ctl_topic_slug (catalog_topic_id, slug),
            UNIQUE INDEX uniq_ctl_topic_position (catalog_topic_id, position),
            UNIQUE INDEX uniq_ctl_topic_content (catalog_topic_id, learning_content_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE catalog_topic_lessons ADD CONSTRAINT FK_CTL_CATALOG_TOPIC FOREIGN KEY (catalog_topic_id) REFERENCES catalog_topics (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE catalog_topic_lessons ADD CONSTRAINT FK_CTL_LEARNING_CONTENT FOREIGN KEY (learning_content_id) REFERENCES learning_contents (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE catalog_topic_lessons ADD CONSTRAINT FK_CTL_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_topic_lessons DROP FOREIGN KEY FK_CTL_CATALOG_TOPIC');
        $this->addSql('ALTER TABLE catalog_topic_lessons DROP FOREIGN KEY FK_CTL_LEARNING_CONTENT');
        $this->addSql('ALTER TABLE catalog_topic_lessons DROP FOREIGN KEY FK_CTL_CREATED_BY');
        $this->addSql('DROP TABLE catalog_topic_lessons');

        $this->addSql('ALTER TABLE catalog_subjects DROP FOREIGN KEY FK_CATALOG_SUBJECT_CANONICAL_SUBJECT');
        $this->addSql('DROP INDEX idx_catalog_subject_canonical_subject ON catalog_subjects');
        $this->addSql('ALTER TABLE catalog_subjects DROP canonical_subject_id');
    }
}

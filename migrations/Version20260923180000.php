<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Student-facing course catalog (distinct from Stage 2.7 curriculum programs).
 */
final class Version20260923180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalog_subjects, catalog_units, catalog_topics for student course catalog.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_subjects (id BINARY(16) NOT NULL, grade_level INT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(32) NOT NULL, position INT NOT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_catalog_subject_grade_slug (grade_level, slug), INDEX idx_catalog_subject_grade_status_pos (grade_level, status, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql("ALTER TABLE catalog_subjects ADD CONSTRAINT chk_catalog_subject_status CHECK (status IN ('draft', 'published', 'archived'))");

        $this->addSql('CREATE TABLE catalog_units (id BINARY(16) NOT NULL, subject_id BINARY(16) NOT NULL, name VARCHAR(160) NOT NULL, slug VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(32) NOT NULL, position INT NOT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_catalog_unit_subject_slug (subject_id, slug), INDEX idx_catalog_unit_subject_status_pos (subject_id, status, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE catalog_units ADD CONSTRAINT FK_CATALOG_UNIT_SUBJECT FOREIGN KEY (subject_id) REFERENCES catalog_subjects (id) ON DELETE RESTRICT');
        $this->addSql("ALTER TABLE catalog_units ADD CONSTRAINT chk_catalog_unit_status CHECK (status IN ('draft', 'published', 'archived'))");

        $this->addSql('CREATE TABLE catalog_topics (id BINARY(16) NOT NULL, unit_id BINARY(16) NOT NULL, name VARCHAR(180) NOT NULL, slug VARCHAR(180) NOT NULL, summary LONGTEXT DEFAULT NULL, status VARCHAR(32) NOT NULL, position INT NOT NULL, estimated_minutes INT DEFAULT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_catalog_topic_unit_slug (unit_id, slug), INDEX idx_catalog_topic_unit_status_pos (unit_id, status, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE catalog_topics ADD CONSTRAINT FK_CATALOG_TOPIC_UNIT FOREIGN KEY (unit_id) REFERENCES catalog_units (id) ON DELETE RESTRICT');
        $this->addSql("ALTER TABLE catalog_topics ADD CONSTRAINT chk_catalog_topic_status CHECK (status IN ('draft', 'published', 'archived'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_topics DROP FOREIGN KEY FK_CATALOG_TOPIC_UNIT');
        $this->addSql('DROP TABLE catalog_topics');
        $this->addSql('ALTER TABLE catalog_units DROP FOREIGN KEY FK_CATALOG_UNIT_SUBJECT');
        $this->addSql('DROP TABLE catalog_units');
        $this->addSql('DROP TABLE catalog_subjects');
    }
}

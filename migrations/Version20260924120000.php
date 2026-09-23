<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catalog source provenance metadata (MEB/TYMM import identity).
 *
 * Identity key for imported rows: (source_version, source_code, source_occurrence).
 * source_occurrence disambiguates the same official code across multiple teaching themes
 * (e.g. MAT.1.1 as Sayılar ve Nicelikler (1)/(2)/(3)). Manual admin rows leave source_* NULL.
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable source provenance columns to catalog_subjects/units/topics.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE catalog_subjects ADD source_code VARCHAR(64) DEFAULT NULL, ADD source_version VARCHAR(32) DEFAULT NULL, ADD source_url VARCHAR(500) DEFAULT NULL, ADD source_occurrence SMALLINT UNSIGNED DEFAULT 1 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_catalog_subject_source ON catalog_subjects (source_version, source_code, source_occurrence)');
        $this->addSql('CREATE INDEX idx_catalog_subject_source_lookup ON catalog_subjects (source_version, source_code)');

        $this->addSql('ALTER TABLE catalog_units ADD source_code VARCHAR(64) DEFAULT NULL, ADD source_version VARCHAR(32) DEFAULT NULL, ADD source_url VARCHAR(500) DEFAULT NULL, ADD source_occurrence SMALLINT UNSIGNED DEFAULT 1 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_catalog_unit_source ON catalog_units (source_version, source_code, source_occurrence)');
        $this->addSql('CREATE INDEX idx_catalog_unit_source_lookup ON catalog_units (source_version, source_code)');

        $this->addSql('ALTER TABLE catalog_topics ADD source_code VARCHAR(64) DEFAULT NULL, ADD source_version VARCHAR(32) DEFAULT NULL, ADD source_url VARCHAR(500) DEFAULT NULL, ADD source_occurrence SMALLINT UNSIGNED DEFAULT 1 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_catalog_topic_source ON catalog_topics (source_version, source_code, source_occurrence)');
        $this->addSql('CREATE INDEX idx_catalog_topic_source_lookup ON catalog_topics (source_version, source_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_catalog_topic_source_lookup ON catalog_topics');
        $this->addSql('DROP INDEX uniq_catalog_topic_source ON catalog_topics');
        $this->addSql('ALTER TABLE catalog_topics DROP source_code, DROP source_version, DROP source_url, DROP source_occurrence');

        $this->addSql('DROP INDEX idx_catalog_unit_source_lookup ON catalog_units');
        $this->addSql('DROP INDEX uniq_catalog_unit_source ON catalog_units');
        $this->addSql('ALTER TABLE catalog_units DROP source_code, DROP source_version, DROP source_url, DROP source_occurrence');

        $this->addSql('DROP INDEX idx_catalog_subject_source_lookup ON catalog_subjects');
        $this->addSql('DROP INDEX uniq_catalog_subject_source ON catalog_subjects');
        $this->addSql('ALTER TABLE catalog_subjects DROP source_code, DROP source_version, DROP source_url, DROP source_occurrence');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assessment identity gains a stable code, optional canonical subject, and first-publish timestamp.
 * Content stays on sealed revisions. Production assessment rows are expected empty; code backfill uses id bytes.
 */
final class Version20260925220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add assessments.code, assessments.subject_id, and assessments.published_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assessments ADD code VARCHAR(32) DEFAULT NULL, ADD subject_id BINARY(16) DEFAULT NULL, ADD published_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE assessments SET code = LOWER(HEX(id)) WHERE code IS NULL');
        $this->addSql('ALTER TABLE assessments MODIFY code VARCHAR(32) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_assessment_code ON assessments (code)');
        $this->addSql('CREATE INDEX idx_assessment_subject ON assessments (subject_id)');
        $this->addSql('ALTER TABLE assessments ADD CONSTRAINT FK_ASSESSMENT_SUBJECT FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assessments DROP FOREIGN KEY FK_ASSESSMENT_SUBJECT');
        $this->addSql('DROP INDEX uniq_assessment_code ON assessments');
        $this->addSql('DROP INDEX idx_assessment_subject ON assessments');
        $this->addSql('ALTER TABLE assessments DROP code, DROP subject_id, DROP published_at');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * X01 student homework optional single PDF attachment (draft only; no public URL).
 */
final class Version20261122180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add homework_submission_attachments for optional student PDF (X01).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE homework_submission_attachments (
            id BINARY(16) NOT NULL,
            submission_id BINARY(16) NOT NULL,
            storage_key CHAR(32) NOT NULL,
            display_name VARCHAR(132) NOT NULL,
            byte_size INT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_hw_sub_attach_submission (submission_id),
            UNIQUE INDEX uniq_hw_sub_attach_storage (storage_key),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE homework_submission_attachments ADD CONSTRAINT FK_HW_SUB_ATTACH_SUBMISSION FOREIGN KEY (submission_id) REFERENCES homework_submissions (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE homework_submission_attachments DROP FOREIGN KEY FK_HW_SUB_ATTACH_SUBMISSION');
        $this->addSql('DROP TABLE homework_submission_attachments');
    }
}

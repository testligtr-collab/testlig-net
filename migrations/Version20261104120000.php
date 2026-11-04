<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * X01 student text submission slice: one submission row per recipient.
 * No evaluation tables. No backfill of existing recipients.
 */
final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add homework_submissions for student text draft/submit (X01).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE homework_submissions (
            id BINARY(16) NOT NULL,
            assignment_id BINARY(16) NOT NULL,
            recipient_id BINARY(16) NOT NULL,
            student_user_id BINARY(16) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            status VARCHAR(32) NOT NULL,
            submitted_at DATETIME DEFAULT NULL COMMENT \'UTC\',
            revision_number INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_homework_sub_recipient (recipient_id),
            UNIQUE INDEX uniq_homework_sub_assignment_student (assignment_id, student_user_id),
            INDEX idx_homework_sub_student (student_user_id),
            INDEX idx_homework_sub_assignment (assignment_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE homework_submissions ADD CONSTRAINT FK_HOMEWORK_SUB_ASSIGNMENT FOREIGN KEY (assignment_id) REFERENCES homework_assignments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_submissions ADD CONSTRAINT FK_HOMEWORK_SUB_RECIPIENT FOREIGN KEY (recipient_id) REFERENCES homework_recipients (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_submissions ADD CONSTRAINT FK_HOMEWORK_SUB_STUDENT FOREIGN KEY (student_user_id) REFERENCES users (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE homework_submissions DROP FOREIGN KEY FK_HOMEWORK_SUB_ASSIGNMENT');
        $this->addSql('ALTER TABLE homework_submissions DROP FOREIGN KEY FK_HOMEWORK_SUB_RECIPIENT');
        $this->addSql('ALTER TABLE homework_submissions DROP FOREIGN KEY FK_HOMEWORK_SUB_STUDENT');
        $this->addSql('DROP TABLE homework_submissions');
    }
}

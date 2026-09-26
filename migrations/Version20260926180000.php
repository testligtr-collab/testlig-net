<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Open parent-link codes. The digest is unique; plaintext is not stored.
 * student_user_id is covered by idx_psl_code_student_created, so the RESTRICT
 * foreign key does not add a second index. down() drops only this table.
 */
final class Version20260926180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create parent_student_link_codes (digest only, reversible drop)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE parent_student_link_codes (
                id BINARY(16) NOT NULL,
                student_user_id BINARY(16) NOT NULL,
                token_digest VARCHAR(64) NOT NULL,
                pepper_key_id VARCHAR(32) NOT NULL,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME DEFAULT NULL,
                revoked_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_psl_code_digest (token_digest),
                INDEX idx_psl_code_student_created (student_user_id, created_at),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE parent_student_link_codes ADD CONSTRAINT FK_EDA9B1CE4A58666D FOREIGN KEY (student_user_id) REFERENCES users (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parent_student_link_codes DROP FOREIGN KEY FK_EDA9B1CE4A58666D');
        $this->addSql('DROP TABLE parent_student_link_codes');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.22.5a — parent–student link foundation (no child-data access wiring).
 *
 * Active-pair uniqueness uses parent_student_link_active_guards composite PK (not a
 * partial unique). CHECK constraints are intentional beyond Doctrine defaults.
 */
final class Version20260921180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add parent_student_links and active-pair guards (Stage 2.22.5a).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE parent_student_links (id BINARY(16) NOT NULL, parent_user_id BINARY(16) NOT NULL, student_user_id BINARY(16) NOT NULL, status VARCHAR(32) NOT NULL, requested_by_user_id BINARY(16) NOT NULL, verified_by_user_id BINARY(16) DEFAULT NULL, requested_at DATETIME NOT NULL, verified_at DATETIME DEFAULT NULL, ended_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_8533BB17D526A7D3 (parent_user_id), INDEX IDX_8533BB174A58666D (student_user_id), INDEX IDX_8533BB17A2DD2669 (requested_by_user_id), INDEX IDX_8533BB17C60EADF2 (verified_by_user_id), INDEX idx_psl_parent_status (parent_user_id, status), INDEX idx_psl_student_status (student_user_id, status), INDEX idx_psl_requested_at (requested_at), UNIQUE INDEX uniq_psl_id_parent_student (id, parent_user_id, student_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE parent_student_links ADD CONSTRAINT FK_8533BB17D526A7D3 FOREIGN KEY (parent_user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE parent_student_links ADD CONSTRAINT FK_8533BB174A58666D FOREIGN KEY (student_user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE parent_student_links ADD CONSTRAINT FK_8533BB17A2DD2669 FOREIGN KEY (requested_by_user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE parent_student_links ADD CONSTRAINT FK_8533BB17C60EADF2 FOREIGN KEY (verified_by_user_id) REFERENCES users (id)');
        $this->addSql("ALTER TABLE parent_student_links ADD CONSTRAINT chk_psl_status CHECK (status IN ('pending', 'verified', 'ended'))");
        $this->addSql('ALTER TABLE parent_student_links ADD CONSTRAINT chk_psl_parent_ne_student CHECK (parent_user_id <> student_user_id)');
        $this->addSql("ALTER TABLE parent_student_links ADD CONSTRAINT chk_psl_lifecycle CHECK ((status = 'pending' AND verified_at IS NULL AND ended_at IS NULL AND verified_by_user_id IS NULL) OR (status = 'verified' AND verified_at IS NOT NULL AND ended_at IS NULL AND verified_at >= requested_at) OR (status = 'ended' AND ended_at IS NOT NULL AND ended_at >= requested_at AND (verified_at IS NULL OR (verified_at <= ended_at AND verified_at >= requested_at))))");

        $this->addSql('CREATE TABLE parent_student_link_active_guards (parent_user_id BINARY(16) NOT NULL, student_user_id BINARY(16) NOT NULL, link_id BINARY(16) NOT NULL, INDEX IDX_8C77D151D526A7D3 (parent_user_id), INDEX IDX_8C77D1514A58666D (student_user_id), UNIQUE INDEX uniq_psl_active_guard_link (link_id), PRIMARY KEY (parent_user_id, student_user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE parent_student_link_active_guards ADD CONSTRAINT FK_8C77D151D526A7D3 FOREIGN KEY (parent_user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE parent_student_link_active_guards ADD CONSTRAINT FK_8C77D1514A58666D FOREIGN KEY (student_user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE parent_student_link_active_guards ADD CONSTRAINT FK_8C77D151ADA40271 FOREIGN KEY (link_id) REFERENCES parent_student_links (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE parent_student_link_active_guards ADD CONSTRAINT chk_psl_guard_parent_ne_student CHECK (parent_user_id <> student_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parent_student_link_active_guards DROP FOREIGN KEY FK_8C77D151ADA40271');
        $this->addSql('ALTER TABLE parent_student_link_active_guards DROP FOREIGN KEY FK_8C77D1514A58666D');
        $this->addSql('ALTER TABLE parent_student_link_active_guards DROP FOREIGN KEY FK_8C77D151D526A7D3');
        $this->addSql('DROP TABLE parent_student_link_active_guards');
        $this->addSql('ALTER TABLE parent_student_links DROP FOREIGN KEY FK_8533BB17C60EADF2');
        $this->addSql('ALTER TABLE parent_student_links DROP FOREIGN KEY FK_8533BB17A2DD2669');
        $this->addSql('ALTER TABLE parent_student_links DROP FOREIGN KEY FK_8533BB174A58666D');
        $this->addSql('ALTER TABLE parent_student_links DROP FOREIGN KEY FK_8533BB17D526A7D3');
        $this->addSql('DROP TABLE parent_student_links');
    }
}

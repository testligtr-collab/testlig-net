<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.22.3 — pending teacher and institution onboarding applications.
 */
final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add teacher_applications and institution_applications (Stage 2.22.3).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE teacher_applications (id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, status VARCHAR(32) NOT NULL, decision_reason_code VARCHAR(64) DEFAULT NULL, submitted_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, pending_owner_id BINARY(16) AS (CASE WHEN `status` = \'pending\' THEN `user_id` ELSE NULL END) STORED, UNIQUE INDEX uniq_teacher_app_one_pending (pending_owner_id), INDEX idx_teacher_app_user_status (user_id, status), INDEX idx_teacher_app_status_created (status, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE teacher_applications ADD CONSTRAINT FK_TEACHER_APP_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql("ALTER TABLE teacher_applications ADD CONSTRAINT chk_teacher_app_status CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn', 'superseded'))");
        $this->addSql('ALTER TABLE teacher_applications ADD CONSTRAINT chk_teacher_app_lifecycle CHECK ((status = \'pending\' AND decided_at IS NULL) OR (status <> \'pending\' AND decided_at IS NOT NULL AND decided_at >= submitted_at))');

        $this->addSql('CREATE TABLE institution_applications (id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, proposed_name VARCHAR(180) NOT NULL, proposed_normalized_name VARCHAR(180) NOT NULL, proposed_type VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, decision_reason_code VARCHAR(64) DEFAULT NULL, submitted_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, pending_owner_id BINARY(16) AS (CASE WHEN `status` = \'pending\' THEN `user_id` ELSE NULL END) STORED, UNIQUE INDEX uniq_inst_app_one_pending (pending_owner_id), INDEX idx_inst_app_user_status (user_id, status), INDEX idx_inst_app_status_created (status, created_at), INDEX idx_inst_app_normalized_name (proposed_normalized_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE institution_applications ADD CONSTRAINT FK_INST_APP_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql("ALTER TABLE institution_applications ADD CONSTRAINT chk_inst_app_status CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn', 'superseded'))");
        $this->addSql("ALTER TABLE institution_applications ADD CONSTRAINT chk_inst_app_type CHECK (proposed_type IN ('school', 'course_center', 'tutoring_center', 'other'))");
        $this->addSql('ALTER TABLE institution_applications ADD CONSTRAINT chk_inst_app_lifecycle CHECK ((status = \'pending\' AND decided_at IS NULL) OR (status <> \'pending\' AND decided_at IS NOT NULL AND decided_at >= submitted_at))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institution_applications DROP FOREIGN KEY FK_INST_APP_USER');
        $this->addSql('DROP TABLE institution_applications');
        $this->addSql('ALTER TABLE teacher_applications DROP FOREIGN KEY FK_TEACHER_APP_USER');
        $this->addSql('DROP TABLE teacher_applications');
    }
}

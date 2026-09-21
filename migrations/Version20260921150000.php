<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.22.4a — personal invitations and limited participation codes (digest only).
 */
final class Version20260921150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add personal_invitations and participation_codes (Stage 2.22.4a).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE personal_invitations (id BINARY(16) NOT NULL, created_by_user_id BINARY(16) NOT NULL, intended_recipient_user_id BINARY(16) NOT NULL, institution_id BINARY(16) DEFAULT NULL, classroom_id BINARY(16) DEFAULT NULL, purpose_code VARCHAR(64) NOT NULL, code_digest VARCHAR(64) NOT NULL, pepper_key_id VARCHAR(32) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_personal_invitation_code_digest (code_digest), INDEX IDX_F5B163E17D182D95 (created_by_user_id), INDEX idx_personal_inv_creator_created (created_by_user_id, created_at), INDEX idx_personal_inv_recipient (intended_recipient_user_id), INDEX idx_personal_inv_institution (institution_id), INDEX IDX_F5B163E16278D5A8 (classroom_id), INDEX idx_personal_inv_expires (expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE personal_invitations ADD CONSTRAINT FK_F5B163E17D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE personal_invitations ADD CONSTRAINT FK_F5B163E12C5AE70D FOREIGN KEY (intended_recipient_user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE personal_invitations ADD CONSTRAINT FK_F5B163E110405986 FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE personal_invitations ADD CONSTRAINT FK_F5B163E16278D5A8 FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE CASCADE');
        $this->addSql("ALTER TABLE personal_invitations ADD CONSTRAINT chk_personal_inv_purpose_code CHECK (purpose_code REGEXP BINARY '^[a-z][a-z0-9_]{0,63}$')");
        $this->addSql("ALTER TABLE personal_invitations ADD CONSTRAINT chk_personal_inv_code_digest_hex CHECK (code_digest REGEXP BINARY '^[0-9a-f]{64}$')");
        $this->addSql('ALTER TABLE personal_invitations ADD CONSTRAINT chk_personal_inv_lifecycle CHECK ((consumed_at IS NULL OR revoked_at IS NULL) AND (consumed_at IS NULL OR consumed_at >= created_at) AND (revoked_at IS NULL OR revoked_at >= created_at) AND expires_at > created_at)');
        $this->addSql('ALTER TABLE personal_invitations ADD CONSTRAINT chk_personal_inv_classroom_requires_institution CHECK (classroom_id IS NULL OR institution_id IS NOT NULL)');
        $this->addSql('ALTER TABLE personal_invitations ADD CONSTRAINT chk_personal_inv_recipient_not_creator CHECK (intended_recipient_user_id <> created_by_user_id)');

        $this->addSql('CREATE TABLE participation_codes (id BINARY(16) NOT NULL, created_by_user_id BINARY(16) NOT NULL, institution_id BINARY(16) NOT NULL, classroom_id BINARY(16) DEFAULT NULL, scope VARCHAR(32) NOT NULL, code_digest VARCHAR(64) NOT NULL, pepper_key_id VARCHAR(32) NOT NULL, max_redemptions INT UNSIGNED NOT NULL, redemption_count INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_participation_code_digest (code_digest), INDEX IDX_EA714CC67D182D95 (created_by_user_id), INDEX IDX_EA714CC610405986 (institution_id), INDEX idx_part_code_institution_scope (institution_id, scope), INDEX idx_part_code_classroom (classroom_id), INDEX idx_part_code_creator_created (created_by_user_id, created_at), INDEX idx_part_code_expires (expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE participation_codes ADD CONSTRAINT FK_EA714CC67D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE participation_codes ADD CONSTRAINT FK_EA714CC610405986 FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE participation_codes ADD CONSTRAINT FK_EA714CC66278D5A8 FOREIGN KEY (classroom_id) REFERENCES classrooms (id) ON DELETE CASCADE');
        $this->addSql("ALTER TABLE participation_codes ADD CONSTRAINT chk_part_code_scope CHECK (scope IN ('institution', 'classroom'))");
        $this->addSql("ALTER TABLE participation_codes ADD CONSTRAINT chk_part_code_digest_hex CHECK (code_digest REGEXP BINARY '^[0-9a-f]{64}$')");
        $this->addSql('ALTER TABLE participation_codes ADD CONSTRAINT chk_part_code_redemptions CHECK (max_redemptions >= 1 AND max_redemptions <= 10000 AND redemption_count >= 0 AND redemption_count <= max_redemptions)');
        $this->addSql('ALTER TABLE participation_codes ADD CONSTRAINT chk_part_code_lifecycle CHECK ((revoked_at IS NULL OR revoked_at >= created_at) AND expires_at > created_at)');
        $this->addSql("ALTER TABLE participation_codes ADD CONSTRAINT chk_part_code_scope_classroom CHECK ((scope = 'institution' AND classroom_id IS NULL) OR (scope = 'classroom' AND classroom_id IS NOT NULL))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participation_codes DROP FOREIGN KEY FK_EA714CC66278D5A8');
        $this->addSql('ALTER TABLE participation_codes DROP FOREIGN KEY FK_EA714CC610405986');
        $this->addSql('ALTER TABLE participation_codes DROP FOREIGN KEY FK_EA714CC67D182D95');
        $this->addSql('DROP TABLE participation_codes');
        $this->addSql('ALTER TABLE personal_invitations DROP FOREIGN KEY FK_F5B163E16278D5A8');
        $this->addSql('ALTER TABLE personal_invitations DROP FOREIGN KEY FK_F5B163E110405986');
        $this->addSql('ALTER TABLE personal_invitations DROP FOREIGN KEY FK_F5B163E12C5AE70D');
        $this->addSql('ALTER TABLE personal_invitations DROP FOREIGN KEY FK_F5B163E17D182D95');
        $this->addSql('DROP TABLE personal_invitations');
    }
}

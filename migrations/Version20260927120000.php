<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Teacher invites address an email that may not have a user yet.
 * PersonalInvitation requires an existing recipient and stays parent-link only.
 * token_digest is unique; plaintext is not stored. The pending guard enforces
 * one live invite per institution and normalized email. down() drops only these tables.
 */
final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create institution teacher invitations and the pending-email guard (digest only)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE institution_teacher_invitations (
                id BINARY(16) NOT NULL,
                institution_id BINARY(16) NOT NULL,
                created_by_user_id BINARY(16) NOT NULL,
                normalized_email VARCHAR(180) NOT NULL,
                token_digest VARCHAR(64) NOT NULL,
                pepper_key_id VARCHAR(32) NOT NULL,
                operator_note VARCHAR(280) DEFAULT NULL,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME DEFAULT NULL,
                revoked_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_teacher_invite_digest (token_digest),
                INDEX idx_teacher_invite_institution_created (institution_id, created_at),
                INDEX idx_teacher_invite_creator (created_by_user_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE institution_teacher_invitations ADD CONSTRAINT FK_TEACHER_INVITE_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE institution_teacher_invitations ADD CONSTRAINT FK_TEACHER_INVITE_CREATOR FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql(<<<'SQL'
            CREATE TABLE institution_teacher_invite_pending_guards (
                institution_id BINARY(16) NOT NULL,
                normalized_email VARCHAR(180) NOT NULL,
                invitation_id BINARY(16) NOT NULL,
                UNIQUE INDEX uniq_teacher_invite_pending_invitation (invitation_id),
                PRIMARY KEY (institution_id, normalized_email)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE institution_teacher_invite_pending_guards ADD CONSTRAINT FK_TEACHER_INVITE_GUARD_INSTITUTION FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE institution_teacher_invite_pending_guards ADD CONSTRAINT FK_TEACHER_INVITE_GUARD_INVITATION FOREIGN KEY (invitation_id) REFERENCES institution_teacher_invitations (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institution_teacher_invite_pending_guards DROP FOREIGN KEY FK_TEACHER_INVITE_GUARD_INVITATION');
        $this->addSql('ALTER TABLE institution_teacher_invite_pending_guards DROP FOREIGN KEY FK_TEACHER_INVITE_GUARD_INSTITUTION');
        $this->addSql('DROP TABLE institution_teacher_invite_pending_guards');
        $this->addSql('ALTER TABLE institution_teacher_invitations DROP FOREIGN KEY FK_TEACHER_INVITE_CREATOR');
        $this->addSql('ALTER TABLE institution_teacher_invitations DROP FOREIGN KEY FK_TEACHER_INVITE_INSTITUTION');
        $this->addSql('DROP TABLE institution_teacher_invitations');
    }
}

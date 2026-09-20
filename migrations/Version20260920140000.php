<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.22.2a — nullable verified phone on users + phone_verification_claims.
 */
final class Version20260920140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users phone identity columns and phone_verification_claims (Stage 2.22.2a).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD phone VARCHAR(32) DEFAULT NULL, ADD normalized_phone VARCHAR(20) DEFAULT NULL, ADD phone_verified_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_normalized_phone ON users (normalized_phone)');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT chk_users_phone_triplet CHECK ((phone IS NULL AND normalized_phone IS NULL AND phone_verified_at IS NULL) OR (phone IS NOT NULL AND normalized_phone IS NOT NULL AND phone_verified_at IS NOT NULL))');
        $this->addSql("ALTER TABLE users ADD CONSTRAINT chk_users_normalized_phone_tr_mobile CHECK (normalized_phone IS NULL OR normalized_phone REGEXP BINARY '^\\\\+905[0-9]{9}$')");

        $this->addSql('CREATE TABLE phone_verification_claims (id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, purpose VARCHAR(32) NOT NULL, target_phone VARCHAR(32) NOT NULL, target_normalized_phone VARCHAR(20) NOT NULL, code_digest VARCHAR(64) NOT NULL, pepper_key_id VARCHAR(32) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, failed_attempt_count INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_pvc_user_purpose_created (user_id, purpose, created_at), INDEX idx_pvc_expires_at (expires_at), INDEX idx_pvc_target_normalized_phone (target_normalized_phone), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE phone_verification_claims ADD CONSTRAINT FK_PVC_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql("ALTER TABLE phone_verification_claims ADD CONSTRAINT chk_pvc_target_normalized_phone_tr_mobile CHECK (target_normalized_phone REGEXP BINARY '^\\\\+905[0-9]{9}$')");
        $this->addSql("ALTER TABLE phone_verification_claims ADD CONSTRAINT chk_pvc_code_digest_hex CHECK (code_digest REGEXP BINARY '^[0-9a-f]{64}$')");
        $this->addSql('ALTER TABLE phone_verification_claims ADD CONSTRAINT chk_pvc_failed_attempts CHECK (failed_attempt_count >= 0 AND failed_attempt_count <= 5)');
        $this->addSql('ALTER TABLE phone_verification_claims ADD CONSTRAINT chk_pvc_lifecycle CHECK ((consumed_at IS NULL OR revoked_at IS NULL) AND (consumed_at IS NULL OR consumed_at >= created_at) AND (revoked_at IS NULL OR revoked_at >= created_at) AND expires_at > created_at)');
        $this->addSql("ALTER TABLE phone_verification_claims ADD CONSTRAINT chk_pvc_purpose CHECK (purpose = 'bind_phone')");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Version20260920140000 is irreversible to avoid identity data loss.');
    }
}

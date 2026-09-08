<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Append-only security audit events + one-shot super-admin bootstrap guard.
 */
final class Version20260908143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create security_audit_events and security_bootstrap_guards tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE security_audit_events (id BINARY(16) NOT NULL, occurred_at DATETIME NOT NULL, action VARCHAR(64) NOT NULL, actor_type VARCHAR(16) NOT NULL, actor_user_id BINARY(16) DEFAULT NULL, subject_user_id BINARY(16) DEFAULT NULL, outcome VARCHAR(16) NOT NULL, correlation_id VARCHAR(64) DEFAULT NULL, ip_hash VARCHAR(64) DEFAULT NULL, user_agent_hash VARCHAR(64) DEFAULT NULL, metadata JSON NOT NULL, INDEX idx_security_audit_occurred_at (occurred_at), INDEX idx_security_audit_action (action), INDEX idx_security_audit_actor_user (actor_user_id), INDEX idx_security_audit_subject_user (subject_user_id), INDEX idx_security_audit_action_occurred (action, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE security_bootstrap_guards (name VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) DEFAULT NULL, INDEX IDX_B43757C2A76ED395 (user_id), PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE security_audit_events ADD CONSTRAINT FK_SEC_AUDIT_ACTOR FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE security_audit_events ADD CONSTRAINT FK_SEC_AUDIT_SUBJECT FOREIGN KEY (subject_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE security_bootstrap_guards ADD CONSTRAINT FK_SEC_BOOTSTRAP_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE security_audit_events DROP FOREIGN KEY FK_SEC_AUDIT_ACTOR');
        $this->addSql('ALTER TABLE security_audit_events DROP FOREIGN KEY FK_SEC_AUDIT_SUBJECT');
        $this->addSql('ALTER TABLE security_bootstrap_guards DROP FOREIGN KEY FK_SEC_BOOTSTRAP_USER');
        $this->addSql('DROP TABLE security_audit_events');
        $this->addSql('DROP TABLE security_bootstrap_guards');
    }
}

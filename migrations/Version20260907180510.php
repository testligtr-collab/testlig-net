<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the central users identity table (UUID v7 PK, normalized email unique).
 */
final class Version20260907180510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users table for identity and global roles foundation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, normalized_email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, global_roles JSON NOT NULL, status VARCHAR(32) NOT NULL, email_verified_at DATETIME DEFAULT NULL, last_login_at DATETIME DEFAULT NULL, password_changed_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, locale VARCHAR(16) NOT NULL, timezone VARCHAR(64) NOT NULL, INDEX idx_users_email (email), UNIQUE INDEX uniq_users_normalized_email (normalized_email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE users');
    }
}

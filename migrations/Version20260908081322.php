<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates reset_password_requests for hashed password-reset tokens (SymfonyCasts).
 * Unique user_id enforces at most one outstanding reset request per account.
 */
final class Version20260908081322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reset_password_requests with unique user_id and cascade delete';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reset_password_requests (id INT AUTO_INCREMENT NOT NULL, selector VARCHAR(20) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_reset_password_user (user_id), INDEX idx_reset_password_selector (selector), INDEX idx_reset_password_expires_at (expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE reset_password_requests ADD CONSTRAINT FK_16646B41A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reset_password_requests DROP FOREIGN KEY FK_16646B41A76ED395');
        $this->addSql('DROP TABLE reset_password_requests');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Empty tables for direct moderator module grants. Existing role access is unchanged.
 */
final class Version20261024120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add empty user module permission and version tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_module_permission_states (user_id BINARY(16) NOT NULL, version INT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_module_permission_states ADD CONSTRAINT FK_6D13DB5DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE user_module_permissions (id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, permission_code VARCHAR(64) NOT NULL, INDEX IDX_F822046A76ED395 (user_id), UNIQUE INDEX uniq_user_module_permission (user_id, permission_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_module_permissions ADD CONSTRAINT FK_F822046A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_module_permissions DROP FOREIGN KEY FK_F822046A76ED395');
        $this->addSql('DROP TABLE user_module_permissions');
        $this->addSql('ALTER TABLE user_module_permission_states DROP FOREIGN KEY FK_6D13DB5DA76ED395');
        $this->addSql('DROP TABLE user_module_permission_states');
    }
}

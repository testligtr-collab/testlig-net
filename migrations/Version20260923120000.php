<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Student first-login profile foundation.
 */
final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create student_profiles table for first-login onboarding.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE student_profiles (
            id BINARY(16) NOT NULL,
            user_id BINARY(16) NOT NULL,
            grade_level SMALLINT NOT NULL,
            school_name VARCHAR(160) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            learning_goal VARCHAR(500) DEFAULT NULL,
            onboarding_completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE UNIQUE INDEX uniq_student_profiles_user ON student_profiles (user_id)');
        $this->addSql('ALTER TABLE student_profiles ADD CONSTRAINT FK_STUDENT_PROFILES_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE student_profiles DROP FOREIGN KEY FK_STUDENT_PROFILES_USER');
        $this->addSql('DROP TABLE student_profiles');
    }
}

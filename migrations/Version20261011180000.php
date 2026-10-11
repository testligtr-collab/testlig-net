<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One teacher professional profile per user. Identity fields stay on users.
 */
final class Version20261011180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create teacher_professional_profiles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE teacher_professional_profiles (id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, subject_id BINARY(16) NOT NULL, grade_levels JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_teacher_professional_profiles_user (user_id), INDEX idx_teacher_professional_profiles_subject (subject_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE teacher_professional_profiles ADD CONSTRAINT FK_TEACHER_PRO_PROFILE_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_professional_profiles ADD CONSTRAINT FK_TEACHER_PRO_PROFILE_SUBJECT FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE teacher_professional_profiles DROP FOREIGN KEY FK_TEACHER_PRO_PROFILE_SUBJECT');
        $this->addSql('ALTER TABLE teacher_professional_profiles DROP FOREIGN KEY FK_TEACHER_PRO_PROFILE_USER');
        $this->addSql('DROP TABLE teacher_professional_profiles');
    }
}

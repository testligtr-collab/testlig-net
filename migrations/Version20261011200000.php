<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Global province/district/school catalog and an optional school on an existing teacher profile.
 * Existing profile rows stay null. National catalog import is not part of this migration.
 */
final class Version20261011200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add locations and teacher_professional_profiles.school_location_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE locations (id BINARY(16) NOT NULL, parent_id BINARY(16) DEFAULT NULL, level VARCHAR(16) NOT NULL, name VARCHAR(150) NOT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_locations_parent (parent_id), INDEX idx_locations_parent_level_active (parent_id, level, active), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE locations ADD CONSTRAINT FK_LOCATIONS_PARENT FOREIGN KEY (parent_id) REFERENCES locations (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE teacher_professional_profiles ADD school_location_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE teacher_professional_profiles ADD CONSTRAINT FK_TEACHER_PRO_PROFILE_SCHOOL FOREIGN KEY (school_location_id) REFERENCES locations (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_teacher_professional_profiles_school ON teacher_professional_profiles (school_location_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE teacher_professional_profiles DROP FOREIGN KEY FK_TEACHER_PRO_PROFILE_SCHOOL');
        $this->addSql('DROP INDEX idx_teacher_professional_profiles_school ON teacher_professional_profiles');
        $this->addSql('ALTER TABLE teacher_professional_profiles DROP school_location_id');
        $this->addSql('ALTER TABLE locations DROP FOREIGN KEY FK_LOCATIONS_PARENT');
        $this->addSql('DROP TABLE locations');
    }
}

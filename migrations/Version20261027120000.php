<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Keeps published program identity. Student access is a separate flag.
 * Stage and grade rows are fixed and start open.
 */
final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add curriculum student access and fixed stage and grade gates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE curriculum_programs ADD student_access VARCHAR(32) DEFAULT 'active' NOT NULL");
        $this->addSql('CREATE TABLE curriculum_access_gates (id BINARY(16) NOT NULL, kind VARCHAR(16) NOT NULL, gate_key VARCHAR(16) NOT NULL, label VARCHAR(80) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_curriculum_access_gate (kind, gate_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql(<<<'SQL'
INSERT INTO curriculum_access_gates (id, kind, gate_key, label, status, created_at, updated_at) VALUES
(UNHEX('01900000000070008000000000000001'), 'stage', 'ilkokul', 'İlkokul', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('01900000000070008000000000000002'), 'stage', 'ortaokul', 'Ortaokul', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('01900000000070008000000000000003'), 'stage', 'lise', 'Lise', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('01900000000070008000000000000010'), 'grade', '1', '1. sınıf', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('01900000000070008000000000000011'), 'grade', '2', '2. sınıf', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('01900000000070008000000000000012'), 'grade', '3', '3. sınıf', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('01900000000070008000000000000013'), 'grade', '4', '4. sınıf', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('01900000000070008000000000000014'), 'grade', '5', '5. sınıf', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('01900000000070008000000000000015'), 'grade', '6', '6. sınıf', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('01900000000070008000000000000016'), 'grade', '7', '7. sınıf', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('01900000000070008000000000000017'), 'grade', '8', '8. sınıf', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('01900000000070008000000000000018'), 'grade', '9', '9. sınıf', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('01900000000070008000000000000019'), 'grade', '10', '10. sınıf', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('0190000000007000800000000000001a'), 'grade', '11', '11. sınıf', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00'),
(UNHEX('0190000000007000800000000000001b'), 'grade', '12', '12. sınıf', 'active', '2026-10-27 00:00:00', '2026-10-27 00:00:00')
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE curriculum_access_gates');
        $this->addSql('ALTER TABLE curriculum_programs DROP student_access');
    }
}

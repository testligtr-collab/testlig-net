<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Binds one platform self-serve practice to a student and assessment.
 * The attempt, answers, and scores stay on the existing delivery-backed tables.
 */
final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add assessment_platform_practices for one student practice per platform assessment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE assessment_platform_practices (
                id BINARY(16) NOT NULL,
                user_id BINARY(16) NOT NULL,
                assessment_id BINARY(16) NOT NULL,
                delivery_id BINARY(16) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_platform_practice_user_assessment (user_id, assessment_id),
                INDEX idx_platform_practice_assessment (assessment_id),
                INDEX idx_platform_practice_delivery (delivery_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        $this->addSql('ALTER TABLE assessment_platform_practices ADD CONSTRAINT FK_C126490DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_platform_practices ADD CONSTRAINT FK_C126490DDD3DD5F1 FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE assessment_platform_practices ADD CONSTRAINT FK_C126490D12136921 FOREIGN KEY (delivery_id) REFERENCES assessment_deliveries (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assessment_platform_practices DROP FOREIGN KEY FK_C126490DA76ED395');
        $this->addSql('ALTER TABLE assessment_platform_practices DROP FOREIGN KEY FK_C126490DDD3DD5F1');
        $this->addSql('ALTER TABLE assessment_platform_practices DROP FOREIGN KEY FK_C126490D12136921');
        $this->addSql('DROP TABLE assessment_platform_practices');
    }
}

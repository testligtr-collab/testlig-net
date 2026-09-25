<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Immutable question code and first-publish timestamp.
 * Production questions table is empty; existing rows would be backfilled from id bytes.
 */
final class Version20260925193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add questions.code and questions.published_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE questions ADD code VARCHAR(32) DEFAULT NULL, ADD published_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE questions SET code = LOWER(HEX(id)) WHERE code IS NULL');
        $this->addSql('ALTER TABLE questions MODIFY code VARCHAR(32) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_question_code ON questions (code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_question_code ON questions');
        $this->addSql('ALTER TABLE questions DROP code, DROP published_at');
    }
}

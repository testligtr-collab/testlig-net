<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.22.5b — associate parent–student links with personal invitations.
 */
final class Version20260921190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add personal_invitation_id to parent_student_links (Stage 2.22.5b).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parent_student_links ADD personal_invitation_id BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_psl_personal_invitation ON parent_student_links (personal_invitation_id)');
        $this->addSql('ALTER TABLE parent_student_links ADD CONSTRAINT FK_8533BB17A76ED395 FOREIGN KEY (personal_invitation_id) REFERENCES personal_invitations (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parent_student_links DROP FOREIGN KEY FK_8533BB17A76ED395');
        $this->addSql('DROP INDEX uniq_psl_personal_invitation ON parent_student_links');
        $this->addSql('ALTER TABLE parent_student_links DROP personal_invitation_id');
    }
}

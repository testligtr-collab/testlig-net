<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nullable unique link from an approved institution application to the institution created later.
 * Existing rows stay null. The foreign key uses the same BINARY(16) UUID type as institutions.id.
 */
final class Version20261011140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add institution_applications.created_institution_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institution_applications ADD created_institution_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE institution_applications ADD CONSTRAINT FK_INST_APP_CREATED_INSTITUTION FOREIGN KEY (created_institution_id) REFERENCES institutions (id) ON DELETE RESTRICT');
        $this->addSql('CREATE UNIQUE INDEX uniq_inst_app_created_institution ON institution_applications (created_institution_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE institution_applications DROP FOREIGN KEY FK_INST_APP_CREATED_INSTITUTION');
        $this->addSql('DROP INDEX uniq_inst_app_created_institution ON institution_applications');
        $this->addSql('ALTER TABLE institution_applications DROP created_institution_id');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.15 follow-up: align learning-content FK supporting indexes with Doctrine IDX_* names.
 *
 * Irreversible — down() refuses restore.
 * Does not modify Version20260912120000 or earlier migrations.
 */
final class Version20260912130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename learning content InnoDB FK supporting indexes to Doctrine hashed IDX_* names.';
    }

    public function up(Schema $schema): void
    {
        // Align InnoDB auto-index names with Doctrine association naming (schema:update empty).
        $this->addSql('ALTER TABLE learning_contents RENAME INDEX IDX_LC_PUBLISHED_REVISION TO IDX_908A1569FE671D30');
        $this->addSql('ALTER TABLE learning_contents RENAME INDEX FK_LC_PUBLISHED_REVISION_CONTENT TO IDX_908A1569FE671D30BF396750D133040D');
        $this->addSql('ALTER TABLE learning_content_outcome_alignments RENAME INDEX FK_LCOA_SUBJECT TO IDX_74246D2423EDC87');
        $this->addSql('ALTER TABLE learning_content_outcome_alignments RENAME INDEX FK_LCOA_TOPIC TO IDX_74246D24E58DFE79');
        $this->addSql('ALTER TABLE learning_content_outcome_alignments RENAME INDEX FK_LCOA_PROGRAM_SUBJECT TO IDX_74246D24CBC6880023EDC87');
        $this->addSql('ALTER TABLE learning_content_outcome_alignments RENAME INDEX FK_LCOA_OUTCOME_TOPIC_PROGRAM TO IDX_74246D2435C2B2D5E58DFE79CBC68800');
        $this->addSql('ALTER TABLE learning_content_publications RENAME INDEX FK_LCP_REVISION_CONTENT TO IDX_4A542D1C1DFA7C8F84A0A3ED');
        $this->addSql('ALTER TABLE learning_content_revision_primary_alignment_guards RENAME INDEX FK_LCRPAG_ALIGNMENT_REVISION_PRIMARY TO IDX_7F84930DAB7AC2A01DFA7C8F9D2AE37');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Stage 2.15 learning content index rename migration is irreversible.',
        );
    }
}

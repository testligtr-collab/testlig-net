<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * X01 teacher evaluation slice: immutable evaluations + current pointer on submissions.
 * No backfill. No partial unique indexes (MariaDB 10.11).
 */
final class Version20261104200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add homework_evaluations and submissions.current_evaluation_id (X01).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE homework_evaluations (
            id BINARY(16) NOT NULL,
            submission_id BINARY(16) NOT NULL,
            evaluated_by_user_id BINARY(16) NOT NULL,
            evaluated_by_membership_id BINARY(16) NOT NULL,
            supersedes_evaluation_id BINARY(16) DEFAULT NULL,
            label VARCHAR(32) NOT NULL,
            feedback MEDIUMTEXT DEFAULT NULL,
            recorded_at DATETIME NOT NULL COMMENT \'UTC\',
            UNIQUE INDEX uniq_hw_eval_id_submission (id, submission_id),
            INDEX idx_hw_eval_submission (submission_id),
            INDEX idx_hw_eval_evaluator (evaluated_by_user_id),
            INDEX idx_hw_eval_membership (evaluated_by_membership_id),
            INDEX idx_hw_eval_supersedes (supersedes_evaluation_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE homework_evaluations ADD CONSTRAINT FK_HW_EVAL_SUBMISSION FOREIGN KEY (submission_id) REFERENCES homework_submissions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_evaluations ADD CONSTRAINT FK_HW_EVAL_USER FOREIGN KEY (evaluated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_evaluations ADD CONSTRAINT FK_HW_EVAL_MEMBERSHIP FOREIGN KEY (evaluated_by_membership_id) REFERENCES institution_memberships (id) ON DELETE RESTRICT');
        // Simple association FK (Doctrine JoinColumn) + same-submission composite (LearningContent pattern).
        $this->addSql('ALTER TABLE homework_evaluations ADD CONSTRAINT FK_HW_EVAL_SUPERSEDES FOREIGN KEY (supersedes_evaluation_id) REFERENCES homework_evaluations (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_evaluations ADD CONSTRAINT FK_HW_EVAL_SUPERSEDES_SAME FOREIGN KEY (supersedes_evaluation_id, submission_id) REFERENCES homework_evaluations (id, submission_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE homework_submissions ADD current_evaluation_id BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_HW_SUB_CURRENT_EVAL ON homework_submissions (current_evaluation_id)');
        // Simple FK + composite same-submission guarantee (LearningContent current_revision pattern).
        $this->addSql('ALTER TABLE homework_submissions ADD CONSTRAINT FK_HW_SUB_CURRENT_EVAL FOREIGN KEY (current_evaluation_id) REFERENCES homework_evaluations (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_submissions ADD CONSTRAINT FK_HW_SUB_CURRENT_EVAL_SAME FOREIGN KEY (current_evaluation_id, id) REFERENCES homework_evaluations (id, submission_id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE homework_submissions DROP FOREIGN KEY FK_HW_SUB_CURRENT_EVAL_SAME');
        $this->addSql('ALTER TABLE homework_submissions DROP FOREIGN KEY FK_HW_SUB_CURRENT_EVAL');
        $this->addSql('DROP INDEX IDX_HW_SUB_CURRENT_EVAL ON homework_submissions');
        $this->addSql('ALTER TABLE homework_submissions DROP current_evaluation_id');

        $this->addSql('ALTER TABLE homework_evaluations DROP FOREIGN KEY FK_HW_EVAL_SUPERSEDES_SAME');
        $this->addSql('ALTER TABLE homework_evaluations DROP FOREIGN KEY FK_HW_EVAL_SUPERSEDES');
        $this->addSql('ALTER TABLE homework_evaluations DROP FOREIGN KEY FK_HW_EVAL_MEMBERSHIP');
        $this->addSql('ALTER TABLE homework_evaluations DROP FOREIGN KEY FK_HW_EVAL_USER');
        $this->addSql('ALTER TABLE homework_evaluations DROP FOREIGN KEY FK_HW_EVAL_SUBMISSION');
        $this->addSql('DROP TABLE homework_evaluations');
    }
}

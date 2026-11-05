<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * X01 teacher evaluation slice: immutable evaluations + current pointer on submissions.
 * No backfill. No partial unique indexes (MariaDB 10.11).
 *
 * Simple FK/index names match Doctrine's generated identifiers; composite FKs are
 * named explicitly and mirrored by HomeworkEvaluationCompositeForeignKeyListener.
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
            INDEX IDX_CE3ACF62761688A6E1FD4933 (supersedes_evaluation_id, submission_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE homework_evaluations ADD CONSTRAINT FK_CE3ACF62E1FD4933 FOREIGN KEY (submission_id) REFERENCES homework_submissions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_evaluations ADD CONSTRAINT FK_CE3ACF6241CCC784 FOREIGN KEY (evaluated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_evaluations ADD CONSTRAINT FK_CE3ACF62854C92D FOREIGN KEY (evaluated_by_membership_id) REFERENCES institution_memberships (id) ON DELETE RESTRICT');
        // Simple association FK (Doctrine JoinColumn) + same-submission composite (LearningContent pattern).
        $this->addSql('ALTER TABLE homework_evaluations ADD CONSTRAINT FK_CE3ACF62761688A6 FOREIGN KEY (supersedes_evaluation_id) REFERENCES homework_evaluations (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_evaluations ADD CONSTRAINT FK_HW_EVAL_SUPERSEDES_SAME FOREIGN KEY (supersedes_evaluation_id, submission_id) REFERENCES homework_evaluations (id, submission_id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE homework_submissions ADD current_evaluation_id BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_HW_SUB_CURRENT_EVAL ON homework_submissions (current_evaluation_id)');
        $this->addSql('CREATE INDEX IDX_CA29CF885492ECA4BF396750 ON homework_submissions (current_evaluation_id, id)');
        // Simple FK + composite same-submission guarantee (LearningContent current_revision pattern).
        $this->addSql('ALTER TABLE homework_submissions ADD CONSTRAINT FK_CA29CF885492ECA4 FOREIGN KEY (current_evaluation_id) REFERENCES homework_evaluations (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE homework_submissions ADD CONSTRAINT FK_HW_SUB_CURRENT_EVAL_SAME FOREIGN KEY (current_evaluation_id, id) REFERENCES homework_evaluations (id, submission_id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE homework_submissions DROP FOREIGN KEY FK_HW_SUB_CURRENT_EVAL_SAME');
        $this->addSql('ALTER TABLE homework_submissions DROP FOREIGN KEY FK_CA29CF885492ECA4');
        $this->addSql('DROP INDEX IDX_CA29CF885492ECA4BF396750 ON homework_submissions');
        $this->addSql('DROP INDEX IDX_HW_SUB_CURRENT_EVAL ON homework_submissions');
        $this->addSql('ALTER TABLE homework_submissions DROP current_evaluation_id');

        $this->addSql('ALTER TABLE homework_evaluations DROP FOREIGN KEY FK_HW_EVAL_SUPERSEDES_SAME');
        $this->addSql('ALTER TABLE homework_evaluations DROP FOREIGN KEY FK_CE3ACF62761688A6');
        $this->addSql('ALTER TABLE homework_evaluations DROP FOREIGN KEY FK_CE3ACF62854C92D');
        $this->addSql('ALTER TABLE homework_evaluations DROP FOREIGN KEY FK_CE3ACF6241CCC784');
        $this->addSql('ALTER TABLE homework_evaluations DROP FOREIGN KEY FK_CE3ACF62E1FD4933');
        $this->addSql('DROP TABLE homework_evaluations');
    }
}

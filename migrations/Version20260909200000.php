<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2.8 final hardening:
 * - Replace DELETE triggers with bypass-free versions (no session variables).
 * - Add CHECK that answer_integrity_hmac is exactly 64 lowercase hex chars.
 *
 * Down policy: irreversible for DELETE-trigger security — down reinstalls the same
 * bypass-free DELETE triggers (does not restore @testlig_immutable_delete_bypass).
 * HMAC CHECK is dropped on down.
 *
 * Does not modify Version20260909180000 or Version20260909190000.
 */
final class Version20260909200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove question-bank DELETE trigger bypass; enforce answer_integrity_hmac hex CHECK';
    }

    public function up(Schema $schema): void
    {
        $invalid = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM question_answer_keys
             WHERE answer_integrity_hmac IS NULL
                OR CHAR_LENGTH(answer_integrity_hmac) <> 64
                OR answer_integrity_hmac REGEXP BINARY '[^0-9a-f]'",
        );
        $this->abortIf(
            $invalid > 0,
            \sprintf(
                'Cannot add chk_qak_answer_integrity_hmac: %d question_answer_keys row(s) have invalid HMAC. Backfill or delete via parent questions CASCADE first.',
                $invalid,
            ),
        );

        $this->addSql('ALTER TABLE question_answer_keys ADD CONSTRAINT chk_qak_answer_integrity_hmac CHECK (CHAR_LENGTH(answer_integrity_hmac) = 64 AND answer_integrity_hmac REGEXP BINARY \'^[0-9a-f]{64}$\')');

        $this->addSql('DROP TRIGGER IF EXISTS trg_question_revisions_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_revision_options_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_answer_keys_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_revision_alignments_bd');

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_revisions_bd BEFORE DELETE ON question_revisions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_revisions are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_revision_options_bd BEFORE DELETE ON question_revision_options
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_revision_options are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_answer_keys_bd BEFORE DELETE ON question_answer_keys
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_answer_keys are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_revision_alignments_bd BEFORE DELETE ON question_revision_alignments
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_revision_alignments are append-only';
            END
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Security: do not restore session-variable DELETE bypass from Version20260909190000.
        $this->addSql('ALTER TABLE question_answer_keys DROP CONSTRAINT chk_qak_answer_integrity_hmac');

        $this->addSql('DROP TRIGGER IF EXISTS trg_question_revisions_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_revision_options_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_answer_keys_bd');
        $this->addSql('DROP TRIGGER IF EXISTS trg_question_revision_alignments_bd');

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_revisions_bd BEFORE DELETE ON question_revisions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_revisions are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_revision_options_bd BEFORE DELETE ON question_revision_options
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_revision_options are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_answer_keys_bd BEFORE DELETE ON question_answer_keys
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_answer_keys are append-only';
            END
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_question_revision_alignments_bd BEFORE DELETE ON question_revision_alignments
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'question_revision_alignments are append-only';
            END
            SQL);
    }
}

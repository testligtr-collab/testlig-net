<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Curriculum tree deletes are explicit. A parent delete must not cascade.
 */
final class Version20261026190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stop curriculum unit, topic, and outcome deletes from cascading.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE curriculum_units DROP FOREIGN KEY FK_CU_UNIT_PROGRAM');
        $this->addSql('ALTER TABLE curriculum_units ADD CONSTRAINT FK_CU_UNIT_PROGRAM FOREIGN KEY (curriculum_program_id) REFERENCES curriculum_programs (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE curriculum_topics DROP FOREIGN KEY FK_TOPIC_UNIT');
        $this->addSql('ALTER TABLE curriculum_topics ADD CONSTRAINT FK_TOPIC_UNIT FOREIGN KEY (unit_id) REFERENCES curriculum_units (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_TOPIC');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_TOPIC FOREIGN KEY (topic_id) REFERENCES curriculum_topics (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_UNIT');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_UNIT FOREIGN KEY (unit_id) REFERENCES curriculum_units (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_PROGRAM');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_PROGRAM FOREIGN KEY (curriculum_program_id) REFERENCES curriculum_programs (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_TOPIC_UNIT');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_TOPIC_UNIT FOREIGN KEY (topic_id, unit_id) REFERENCES curriculum_topics (id, unit_id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_UNIT_PROGRAM');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_UNIT_PROGRAM FOREIGN KEY (unit_id, curriculum_program_id) REFERENCES curriculum_units (id, curriculum_program_id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE curriculum_units DROP FOREIGN KEY FK_CU_UNIT_PROGRAM');
        $this->addSql('ALTER TABLE curriculum_units ADD CONSTRAINT FK_CU_UNIT_PROGRAM FOREIGN KEY (curriculum_program_id) REFERENCES curriculum_programs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_topics DROP FOREIGN KEY FK_TOPIC_UNIT');
        $this->addSql('ALTER TABLE curriculum_topics ADD CONSTRAINT FK_TOPIC_UNIT FOREIGN KEY (unit_id) REFERENCES curriculum_units (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_TOPIC');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_TOPIC FOREIGN KEY (topic_id) REFERENCES curriculum_topics (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_UNIT');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_UNIT FOREIGN KEY (unit_id) REFERENCES curriculum_units (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_PROGRAM');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_PROGRAM FOREIGN KEY (curriculum_program_id) REFERENCES curriculum_programs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_TOPIC_UNIT');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_TOPIC_UNIT FOREIGN KEY (topic_id, unit_id) REFERENCES curriculum_topics (id, unit_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes DROP FOREIGN KEY FK_CLO_UNIT_PROGRAM');
        $this->addSql('ALTER TABLE curriculum_learning_outcomes ADD CONSTRAINT FK_CLO_UNIT_PROGRAM FOREIGN KEY (unit_id, curriculum_program_id) REFERENCES curriculum_units (id, curriculum_program_id) ON DELETE CASCADE');
    }
}

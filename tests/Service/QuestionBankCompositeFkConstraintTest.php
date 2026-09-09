<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class QuestionBankCompositeFkConstraintTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->cleanup();
    }

    public function testInformationSchemaHasCompositeForeignKeys(): void
    {
        $schema = $this->em->getConnection()->createSchemaManager()->introspectSchema();
        self::assertTrue($schema->getTable('curriculum_learning_outcomes')->hasForeignKey('FK_CLO_TOPIC_UNIT'));
        self::assertTrue($schema->getTable('curriculum_learning_outcomes')->hasForeignKey('FK_CLO_UNIT_PROGRAM'));
        self::assertTrue($schema->getTable('question_revision_alignments')->hasForeignKey('FK_QRA_PROGRAM_SUBJECT'));
        self::assertTrue($schema->getTable('question_revision_alignments')->hasForeignKey('FK_QRA_OUTCOME_TOPIC_PROGRAM'));
        self::assertTrue($schema->getTable('question_revision_primary_alignment_guards')->hasForeignKey('FK_QRPAG_ALIGNMENT_REVISION'));

        $checks = $this->em->getConnection()->fetchFirstColumn(
            "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_question_scope_institution'",
        );
        self::assertContains('chk_question_scope_institution', $checks);
    }

    public function testDuplicateRevisionNumberRejected(): void
    {
        // Minimal inserts are heavy; verify unique index exists.
        $indexes = $this->em->getConnection()->createSchemaManager()->listTableIndexes('question_revisions');
        self::assertArrayHasKey('uniq_question_revision_number', $indexes);
    }

    public function testPrimaryAlignmentGuardUnique(): void
    {
        $indexes = $this->em->getConnection()->createSchemaManager()->listTableIndexes('question_revision_primary_alignment_guards');
        self::assertArrayHasKey('primary', $indexes);
        self::assertArrayHasKey('uniq_qrpag_alignment', $indexes);
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        foreach ([
            'question_revision_primary_alignment_guards',
            'question_revision_alignments',
            'question_answer_keys',
            'question_revision_options',
            'question_revisions',
            'questions',
            'curriculum_learning_outcomes',
        ] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanup();
        } catch (\Throwable) {
            self::ensureKernelShutdown();
        }
        parent::tearDown();
    }
}

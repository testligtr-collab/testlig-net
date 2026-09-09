<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Tests\Support\AssessmentDbCleanup;
use App\Tests\Support\QuestionBankDbCleanup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssessmentCompositeFkConstraintTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        AssessmentDbCleanup::deleteAssessments($this->em->getConnection());
        QuestionBankDbCleanup::deleteTables($this->em->getConnection());
    }

    public function testInformationSchemaHasAssessmentCompositeForeignKeysAndChecks(): void
    {
        $schema = $this->em->getConnection()->createSchemaManager()->introspectSchema();
        self::assertTrue($schema->getTable('assessment_items')->hasForeignKey('FK_AI_SECTION_REVISION'));
        self::assertTrue($schema->getTable('assessment_items')->hasForeignKey('FK_AI_QUESTION_REVISION_CHAIN'));
        self::assertTrue($schema->getTable('assessment_publications')->hasForeignKey('FK_AP_REVISION_ASSESSMENT'));

        $checks = $this->em->getConnection()->fetchFirstColumn(
            "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME IN (
                 'chk_assessment_scope_institution',
                 'chk_assessment_item_points',
                 'chk_assessment_item_penalty',
                 'chk_assessment_revision_hash'
               )",
        );
        self::assertContains('chk_assessment_scope_institution', $checks);
        self::assertContains('chk_assessment_item_points', $checks);
        self::assertContains('chk_assessment_item_penalty', $checks);
        self::assertContains('chk_assessment_revision_hash', $checks);

        $indexes = $this->em->getConnection()->createSchemaManager()->listTableIndexes('assessment_revisions');
        self::assertArrayHasKey('uniq_assessment_revision_number', $indexes);
        $pubIndexes = $this->em->getConnection()->createSchemaManager()->listTableIndexes('assessment_publications');
        self::assertArrayHasKey('uniq_assessment_publication_revision', $pubIndexes);
        self::assertArrayHasKey('uniq_assessment_publication_number', $pubIndexes);
    }
}

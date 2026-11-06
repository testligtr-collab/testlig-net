<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Tests\Support\HomeworkTestFixtures;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * MariaDB must reject cross-submission current pointers and supersede links.
 */
final class HomeworkEvaluationCompositeFkConstraintTest extends KernelTestCase
{
    use HomeworkTestFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rebindDeliveryFixtures();
        $this->cleanupHomework();
    }

    protected function tearDown(): void
    {
        $this->resetClock();
        if (isset($this->em)) {
            $this->cleanupHomework();
        }
        parent::tearDown();
    }

    public function testInformationSchemaListsSameSubmissionCompositeFks(): void
    {
        $found = $this->em->getConnection()->fetchFirstColumn(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND CONSTRAINT_TYPE = \'FOREIGN KEY\'
               AND CONSTRAINT_NAME IN (?, ?)
             ORDER BY CONSTRAINT_NAME ASC',
            ['FK_HW_EVAL_SUPERSEDES_SAME', 'FK_HW_SUB_CURRENT_EVAL_SAME'],
        );

        self::assertSame(
            ['FK_HW_EVAL_SUPERSEDES_SAME', 'FK_HW_SUB_CURRENT_EVAL_SAME'],
            $found,
            'evaluation same-submission composite FKs must exist',
        );
    }

    public function testCrossSubmissionCurrentPointerAndSupersedesAreRejected(): void
    {
        $s = $this->scenario('hwe-fk', 2);
        $teacher = $s['ctx']['teacher'];
        $manager = $this->homeworkEvaluationManager();

        $firstId = $this->studentSubmit($s['users'][0], $s['assignment'], 'Birinci teslim')->getId();
        $secondId = $this->studentSubmit($s['users'][1], $s['assignment'], 'Ikinci teslim')->getId();
        $firstEval = $manager->record($teacher, $firstId, 'reviewed', 'Ilk degerlendirme', null);
        $secondEval = $manager->record($teacher, $secondId, 'reviewed', 'Ikinci degerlendirme', null);

        $connection = $this->em->getConnection();
        $pointerRejected = false;
        try {
            $connection->executeStatement(
                'UPDATE homework_submissions SET current_evaluation_id = :eval WHERE id = :submission',
                [
                    'eval' => $firstEval->getId()->toBinary(),
                    'submission' => $secondId->toBinary(),
                ],
            );
        } catch (ForeignKeyConstraintViolationException) {
            $pointerRejected = true;
        }
        self::assertTrue($pointerRejected, 'cross-submission current_evaluation_id must fail composite FK');
        self::assertSame($secondEval->getId()->toRfc4122(), $this->currentEvaluationId($secondId));

        $supersedeRejected = false;
        try {
            $connection->insert('homework_evaluations', [
                'id' => Uuid::v7()->toBinary(),
                'submission_id' => $secondId->toBinary(),
                'evaluated_by_user_id' => $teacher->getId()->toBinary(),
                'evaluated_by_membership_id' => $s['ctx']['teacherMembership']->getId()->toBinary(),
                'supersedes_evaluation_id' => $firstEval->getId()->toBinary(),
                'label' => 'reviewed',
                'feedback' => 'capraz zincir',
                'recorded_at' => '2026-11-12 10:00:00',
            ]);
        } catch (ForeignKeyConstraintViolationException) {
            $supersedeRejected = true;
        }
        self::assertTrue($supersedeRejected, 'cross-submission supersedes_evaluation_id must fail composite FK');
        self::assertSame(1, $this->evaluationRowCount($firstId));
        self::assertSame(1, $this->evaluationRowCount($secondId));
    }

    /**
     * @return array{
     *     ctx: array{teacher: \App\Entity\User, teacherMembership: \App\Entity\InstitutionMembership},
     *     users: list<\App\Entity\User>,
     *     assignment: \App\Entity\HomeworkAssignment
     * }
     */
    private function scenario(string $prefix, int $students = 1): array
    {
        $ctx = $this->homeworkContext($prefix);
        $memberships = $this->enrollStudents($ctx, $students);
        $this->freezeClock();
        $assignment = $this->assignClassroomHomework($ctx);
        $users = array_map(static fn ($membership) => $membership->getUser(), $memberships);

        return ['ctx' => $ctx, 'users' => $users, 'assignment' => $assignment];
    }
}

<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Adds composite foreign keys for tenant/guard consistency.
 *
 * Doctrine associations can only reference primary-key columns, so composite
 * UNIQUE targets are expressed here + in the SQL migration.
 */
final class AcademicClassroomCompositeForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('institution_active_academic_year_guards')) {
            $this->ensureForeignKey(
                $schema->getTable('institution_active_academic_year_guards'),
                'FK_ACTIVE_AY_GUARD_YEAR_INSTITUTION',
                'academic_years',
                ['academic_year_id', 'institution_id'],
                ['id', 'institution_id'],
            );
        }

        if ($schema->hasTable('classrooms')) {
            $this->ensureForeignKey(
                $schema->getTable('classrooms'),
                'FK_CLASSROOM_YEAR_INSTITUTION',
                'academic_years',
                ['academic_year_id', 'institution_id'],
                ['id', 'institution_id'],
            );
        }

        if ($schema->hasTable('classroom_teacher_assignments')) {
            $table = $schema->getTable('classroom_teacher_assignments');
            $this->ensureForeignKey(
                $table,
                'FK_CTA_CLASSROOM_INSTITUTION',
                'classrooms',
                ['classroom_id', 'institution_id'],
                ['id', 'institution_id'],
            );
            $this->ensureForeignKey(
                $table,
                'FK_CTA_MEMBERSHIP_INSTITUTION',
                'institution_memberships',
                ['teacher_membership_id', 'institution_id'],
                ['id', 'institution_id'],
            );
        }

        if ($schema->hasTable('classroom_homeroom_guards')) {
            $this->ensureForeignKey(
                $schema->getTable('classroom_homeroom_guards'),
                'FK_HOMEROOM_GUARD_ASSIGNMENT_CLASSROOM',
                'classroom_teacher_assignments',
                ['assignment_id', 'classroom_id'],
                ['id', 'classroom_id'],
            );
        }

        if ($schema->hasTable('classroom_teacher_active_guards')) {
            $this->ensureForeignKey(
                $schema->getTable('classroom_teacher_active_guards'),
                'FK_CTA_ACTIVE_GUARD_ASSIGNMENT_KEYS',
                'classroom_teacher_assignments',
                ['assignment_id', 'classroom_id', 'teacher_membership_id'],
                ['id', 'classroom_id', 'teacher_membership_id'],
            );
        }

        if ($schema->hasTable('classroom_student_enrollments')) {
            $table = $schema->getTable('classroom_student_enrollments');
            $this->ensureForeignKey(
                $table,
                'FK_CSE_CLASSROOM_YEAR',
                'classrooms',
                ['classroom_id', 'academic_year_id'],
                ['id', 'academic_year_id'],
            );
            $this->ensureForeignKey(
                $table,
                'FK_CSE_CLASSROOM_INSTITUTION',
                'classrooms',
                ['classroom_id', 'institution_id'],
                ['id', 'institution_id'],
            );
            $this->ensureForeignKey(
                $table,
                'FK_CSE_YEAR_INSTITUTION',
                'academic_years',
                ['academic_year_id', 'institution_id'],
                ['id', 'institution_id'],
            );
            $this->ensureForeignKey(
                $table,
                'FK_CSE_MEMBERSHIP_INSTITUTION',
                'institution_memberships',
                ['student_membership_id', 'institution_id'],
                ['id', 'institution_id'],
            );
        }

        if ($schema->hasTable('academic_year_student_enrollment_guards')) {
            $this->ensureForeignKey(
                $schema->getTable('academic_year_student_enrollment_guards'),
                'FK_AY_ENROLL_GUARD_ENROLLMENT_KEYS',
                'classroom_student_enrollments',
                ['enrollment_id', 'academic_year_id', 'student_membership_id'],
                ['id', 'academic_year_id', 'student_membership_id'],
            );
        }
    }

    /**
     * @param non-empty-list<string> $localColumns
     * @param non-empty-list<string> $foreignColumns
     */
    private function ensureForeignKey(
        Table $table,
        string $name,
        string $foreignTable,
        array $localColumns,
        array $foreignColumns,
    ): void {
        foreach ($table->getForeignKeys() as $existing) {
            if ($existing->getName() === $name) {
                return;
            }
            if ($existing->getLocalColumns() === $localColumns && $existing->getForeignTableName() === $foreignTable) {
                return;
            }
        }

        $table->addForeignKeyConstraint(
            $foreignTable,
            $localColumns,
            $foreignColumns,
            ['onDelete' => 'CASCADE'],
            $name,
        );
    }
}

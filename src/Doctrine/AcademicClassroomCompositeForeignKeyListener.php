<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Adds composite foreign keys for tenant/guard consistency (academic/classroom domain).
 */
final class AcademicClassroomCompositeForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('institution_active_academic_year_guards')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('institution_active_academic_year_guards'),
                'FK_ACTIVE_AY_GUARD_YEAR_INSTITUTION',
                'academic_years',
                ['academic_year_id', 'institution_id'],
                ['id', 'institution_id'],
            );
        }

        if ($schema->hasTable('classrooms')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('classrooms'),
                'FK_CLASSROOM_YEAR_INSTITUTION',
                'academic_years',
                ['academic_year_id', 'institution_id'],
                ['id', 'institution_id'],
            );
        }

        if ($schema->hasTable('classroom_teacher_assignments')) {
            $table = $schema->getTable('classroom_teacher_assignments');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CTA_CLASSROOM_INSTITUTION',
                'classrooms',
                ['classroom_id', 'institution_id'],
                ['id', 'institution_id'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CTA_MEMBERSHIP_INSTITUTION',
                'institution_memberships',
                ['teacher_membership_id', 'institution_id'],
                ['id', 'institution_id'],
            );
        }

        if ($schema->hasTable('classroom_homeroom_guards')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('classroom_homeroom_guards'),
                'FK_HOMEROOM_GUARD_ASSIGNMENT_CLASSROOM',
                'classroom_teacher_assignments',
                ['assignment_id', 'classroom_id'],
                ['id', 'classroom_id'],
            );
        }

        if ($schema->hasTable('classroom_teacher_active_guards')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('classroom_teacher_active_guards'),
                'FK_CTA_ACTIVE_GUARD_ASSIGNMENT_KEYS',
                'classroom_teacher_assignments',
                ['assignment_id', 'classroom_id', 'teacher_membership_id'],
                ['id', 'classroom_id', 'teacher_membership_id'],
            );
        }

        if ($schema->hasTable('classroom_student_enrollments')) {
            $table = $schema->getTable('classroom_student_enrollments');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CSE_CLASSROOM_YEAR',
                'classrooms',
                ['classroom_id', 'academic_year_id'],
                ['id', 'academic_year_id'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CSE_CLASSROOM_INSTITUTION',
                'classrooms',
                ['classroom_id', 'institution_id'],
                ['id', 'institution_id'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CSE_YEAR_INSTITUTION',
                'academic_years',
                ['academic_year_id', 'institution_id'],
                ['id', 'institution_id'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CSE_MEMBERSHIP_INSTITUTION',
                'institution_memberships',
                ['student_membership_id', 'institution_id'],
                ['id', 'institution_id'],
            );
        }

        if ($schema->hasTable('academic_year_student_enrollment_guards')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('academic_year_student_enrollment_guards'),
                'FK_AY_ENROLL_GUARD_ENROLLMENT_KEYS',
                'classroom_student_enrollments',
                ['enrollment_id', 'academic_year_id', 'student_membership_id'],
                ['id', 'academic_year_id', 'student_membership_id'],
            );
        }
    }
}

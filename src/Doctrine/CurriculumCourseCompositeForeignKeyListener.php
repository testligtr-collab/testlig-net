<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * Composite FKs for curriculum / classroom-course domain consistency.
 */
final class CurriculumCourseCompositeForeignKeyListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('curriculum_topics')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('curriculum_topics'),
                'FK_TOPIC_PARENT_SAME_UNIT',
                'curriculum_topics',
                ['parent_id', 'unit_id'],
                ['id', 'unit_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('classroom_courses')) {
            $table = $schema->getTable('classroom_courses');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CC_CLASSROOM_YEAR_INSTITUTION',
                'classrooms',
                ['classroom_id', 'academic_year_id', 'institution_id'],
                ['id', 'academic_year_id', 'institution_id'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CC_CLASSROOM_INSTITUTION',
                'classrooms',
                ['classroom_id', 'institution_id'],
                ['id', 'institution_id'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CC_YEAR_INSTITUTION',
                'academic_years',
                ['academic_year_id', 'institution_id'],
                ['id', 'institution_id'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CC_PROGRAM_SUBJECT',
                'curriculum_programs',
                ['curriculum_program_id', 'subject_id'],
                ['id', 'subject_id'],
                ['onDelete' => 'RESTRICT'],
            );
        }

        if ($schema->hasTable('classroom_course_active_guards')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('classroom_course_active_guards'),
                'FK_CC_ACTIVE_GUARD_COURSE_KEYS',
                'classroom_courses',
                ['course_id', 'classroom_id', 'subject_id'],
                ['id', 'classroom_id', 'subject_id'],
            );
        }

        if ($schema->hasTable('course_teacher_assignments')) {
            $table = $schema->getTable('course_teacher_assignments');
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CTEACH_COURSE_INSTITUTION',
                'classroom_courses',
                ['classroom_course_id', 'institution_id'],
                ['id', 'institution_id'],
            );
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $table,
                'FK_CTEACH_MEMBERSHIP_INSTITUTION',
                'institution_memberships',
                ['teacher_membership_id', 'institution_id'],
                ['id', 'institution_id'],
            );
        }

        if ($schema->hasTable('course_teacher_active_guards')) {
            CompositeForeignKeySchemaHelper::ensureForeignKey(
                $schema->getTable('course_teacher_active_guards'),
                'FK_CTEACH_ACTIVE_GUARD_ASSIGNMENT_KEYS',
                'course_teacher_assignments',
                ['assignment_id', 'classroom_course_id', 'teacher_membership_id'],
                ['id', 'classroom_course_id', 'teacher_membership_id'],
            );
        }
    }
}

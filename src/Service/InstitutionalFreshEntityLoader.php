<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\AcademicYearStudentEnrollmentGuard;
use App\Entity\Classroom;
use App\Entity\ClassroomCourse;
use App\Entity\ClassroomCourseActiveGuard;
use App\Entity\ClassroomHomeroomGuard;
use App\Entity\ClassroomStudentEnrollment;
use App\Entity\ClassroomTeacherActiveGuard;
use App\Entity\ClassroomTeacherAssignment;
use App\Entity\CourseTeacherActiveGuard;
use App\Entity\CourseTeacherAssignment;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\CurriculumProgram;
use App\Entity\CurriculumTopic;
use App\Entity\CurriculumUnit;
use App\Entity\Institution;
use App\Entity\InstitutionActiveAcademicYearGuard;
use App\Entity\InstitutionMembership;
use App\Entity\Question;
use App\Entity\Subject;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

/**
 * Loads institution-domain entities with an explicit identity-map bypass.
 *
 * User fresh-loads delegate to {@see FreshUserLoader}. Institution/Membership
 * and academic/classroom loaders keep the same HINT_REFRESH + lock guarantees.
 *
 * Lock order for callers that mutate: Institution → AcademicYear → Classroom
 * → ClassroomCourse → Users (UUID asc) → Membership → Assignment/Enrollment/Guards.
 * Curriculum: Subject → CurriculumProgram → Unit → Topic.
 */
final class InstitutionalFreshEntityLoader
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FreshUserLoader $freshUsers,
    ) {
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, User> keyed by RFC4122; missing users are omitted
     */
    public function findFreshLockedUsers(array $ids, LockMode $lockMode = LockMode::PESSIMISTIC_READ): array
    {
        return $this->freshUsers->findFreshLockedUsers($ids, $lockMode);
    }

    public function findFreshLockedUser(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_READ): ?User
    {
        return $this->freshUsers->findFreshLockedUser($id, $lockMode);
    }

    public function findFreshLockedInstitution(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?Institution
    {
        $entity = $this->findFresh(Institution::class, $id, $lockMode);

        return $entity instanceof Institution ? $entity : null;
    }

    public function findFreshLockedMembership(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?InstitutionMembership
    {
        $entity = $this->findFresh(InstitutionMembership::class, $id, $lockMode);

        return $entity instanceof InstitutionMembership ? $entity : null;
    }

    public function findFreshLockedAcademicYear(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?AcademicYear
    {
        $entity = $this->findFresh(AcademicYear::class, $id, $lockMode);

        return $entity instanceof AcademicYear ? $entity : null;
    }

    public function findFreshLockedClassroom(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?Classroom
    {
        $entity = $this->findFresh(Classroom::class, $id, $lockMode);

        return $entity instanceof Classroom ? $entity : null;
    }

    public function findFreshLockedSubject(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?Subject
    {
        $entity = $this->findFresh(Subject::class, $id, $lockMode);

        return $entity instanceof Subject ? $entity : null;
    }

    public function findFreshLockedCurriculumProgram(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?CurriculumProgram
    {
        $entity = $this->findFresh(CurriculumProgram::class, $id, $lockMode);

        return $entity instanceof CurriculumProgram ? $entity : null;
    }

    public function findFreshLockedCurriculumUnit(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?CurriculumUnit
    {
        $entity = $this->findFresh(CurriculumUnit::class, $id, $lockMode);

        return $entity instanceof CurriculumUnit ? $entity : null;
    }

    public function findFreshLockedCurriculumTopic(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?CurriculumTopic
    {
        $entity = $this->findFresh(CurriculumTopic::class, $id, $lockMode);

        return $entity instanceof CurriculumTopic ? $entity : null;
    }

    public function findFreshLockedCurriculumLearningOutcome(
        Uuid $id,
        LockMode $lockMode = LockMode::PESSIMISTIC_WRITE,
    ): ?CurriculumLearningOutcome {
        $entity = $this->findFresh(CurriculumLearningOutcome::class, $id, $lockMode);

        return $entity instanceof CurriculumLearningOutcome ? $entity : null;
    }

    public function findFreshLockedQuestion(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?Question
    {
        $entity = $this->findFresh(Question::class, $id, $lockMode);

        return $entity instanceof Question ? $entity : null;
    }

    public function findFreshLockedClassroomCourse(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?ClassroomCourse
    {
        $entity = $this->findFresh(ClassroomCourse::class, $id, $lockMode);

        return $entity instanceof ClassroomCourse ? $entity : null;
    }

    public function findFreshLockedCourseTeacherAssignment(
        Uuid $id,
        LockMode $lockMode = LockMode::PESSIMISTIC_WRITE,
    ): ?CourseTeacherAssignment {
        $entity = $this->findFresh(CourseTeacherAssignment::class, $id, $lockMode);

        return $entity instanceof CourseTeacherAssignment ? $entity : null;
    }

    public function findFreshClassroomCourseActiveGuard(
        Uuid $classroomId,
        Uuid $subjectId,
    ): ?ClassroomCourseActiveGuard {
        return $this->findFreshAssociationId(
            ClassroomCourseActiveGuard::class,
            ['classroom' => $classroomId, 'subject' => $subjectId],
            LockMode::NONE,
        );
    }

    public function findFreshCourseTeacherActiveGuard(
        Uuid $classroomCourseId,
        Uuid $teacherMembershipId,
    ): ?CourseTeacherActiveGuard {
        return $this->findFreshAssociationId(
            CourseTeacherActiveGuard::class,
            ['classroomCourse' => $classroomCourseId, 'teacherMembership' => $teacherMembershipId],
            LockMode::NONE,
        );
    }

    public function findFreshLockedTeacherAssignment(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?ClassroomTeacherAssignment
    {
        $entity = $this->findFresh(ClassroomTeacherAssignment::class, $id, $lockMode);

        return $entity instanceof ClassroomTeacherAssignment ? $entity : null;
    }

    public function findFreshLockedStudentEnrollment(Uuid $id, LockMode $lockMode = LockMode::PESSIMISTIC_WRITE): ?ClassroomStudentEnrollment
    {
        $entity = $this->findFresh(ClassroomStudentEnrollment::class, $id, $lockMode);

        return $entity instanceof ClassroomStudentEnrollment ? $entity : null;
    }

    public function findFreshLockedActiveAcademicYearGuard(
        Uuid $institutionId,
        LockMode $lockMode = LockMode::PESSIMISTIC_WRITE,
    ): ?InstitutionActiveAcademicYearGuard {
        return $this->findFreshAssociationId(
            InstitutionActiveAcademicYearGuard::class,
            ['institution' => $institutionId],
            $lockMode,
        );
    }

    public function findFreshLockedHomeroomGuard(
        Uuid $classroomId,
        LockMode $lockMode = LockMode::PESSIMISTIC_WRITE,
    ): ?ClassroomHomeroomGuard {
        return $this->findFreshAssociationId(
            ClassroomHomeroomGuard::class,
            ['classroom' => $classroomId],
            $lockMode,
        );
    }

    public function findFreshLockedTeacherActiveGuard(
        Uuid $classroomId,
        Uuid $teacherMembershipId,
        LockMode $lockMode = LockMode::PESSIMISTIC_WRITE,
    ): ?ClassroomTeacherActiveGuard {
        return $this->findFreshAssociationId(
            ClassroomTeacherActiveGuard::class,
            ['classroom' => $classroomId, 'teacherMembership' => $teacherMembershipId],
            $lockMode,
        );
    }

    public function findFreshLockedEnrollmentGuard(
        Uuid $academicYearId,
        Uuid $studentMembershipId,
        LockMode $lockMode = LockMode::PESSIMISTIC_WRITE,
    ): ?AcademicYearStudentEnrollmentGuard {
        return $this->findFreshAssociationId(
            AcademicYearStudentEnrollmentGuard::class,
            ['academicYear' => $academicYearId, 'studentMembership' => $studentMembershipId],
            $lockMode,
        );
    }

    /**
     * Fresh membership for (user, institution). Uses HINT_REFRESH; optional lock.
     * Call only after the institution row is already locked when used in mutations.
     */
    public function findFreshMembershipForUser(
        Uuid $userId,
        Uuid $institutionId,
        ?LockMode $lockMode = null,
    ): ?InstitutionMembership {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(InstitutionMembership::class, 'm')
            ->where('m.user = :userId')
            ->andWhere('m.institution = :institutionId')
            ->setParameter('userId', $userId, 'uuid')
            ->setParameter('institutionId', $institutionId, 'uuid');

        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        if (null !== $lockMode) {
            $query->setLockMode($lockMode);
        }

        $result = $query->getOneOrNullResult();

        return $result instanceof InstitutionMembership ? $result : null;
    }

    /**
     * @template T of object
     *
     * @param class-string<T>     $class
     * @param array<string, Uuid> $ids
     *
     * @return T|null
     */
    private function findFreshAssociationId(string $class, array $ids, LockMode $lockMode): ?object
    {
        // Guard rows sit under already-locked parents (institution/year/classroom).
        // Avoid SELECT FOR UPDATE on guard tables — it can lock-wait against parent FKs.
        unset($lockMode);

        /** @var T|null $entity */
        $entity = $this->entityManager->find($class, $ids);
        if (null !== $entity) {
            $this->entityManager->refresh($entity);

            return $entity;
        }

        $qb = $this->entityManager->createQueryBuilder()->select('g')->from($class, 'g');
        $i = 0;
        foreach ($ids as $field => $id) {
            $param = 'id'.$i;
            $qb->andWhere(\sprintf('g.%s = :%s', $field, $param))
                ->setParameter($param, $id, 'uuid');
            ++$i;
        }
        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);

        /** @var T|null $entity */
        $entity = $query->getOneOrNullResult();

        return $entity;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function findFresh(string $class, Uuid $id, ?LockMode $lockMode): ?object
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($class, 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id, 'uuid');

        $query = $qb->getQuery();
        $query->setHint(Query::HINT_REFRESH, true);
        if (null !== $lockMode) {
            $query->setLockMode($lockMode);
        }

        /** @var T|null $result */
        $result = $query->getOneOrNullResult();

        return $result;
    }
}

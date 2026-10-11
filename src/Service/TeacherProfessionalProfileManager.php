<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TeacherProfessionalProfileRequest;
use App\Entity\Location;
use App\Entity\Subject;
use App\Entity\TeacherProfessionalProfile;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\SubjectStatus;
use App\Enum\UserRole;
use App\Exception\TeacherProfessionalProfileException;
use App\Repository\LocationRepository;
use App\Repository\TeacherProfessionalProfileRepository;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Saves the signed-in teacher's own subject and grades.
 *
 * Does not approve applications, grant roles, or create institution memberships.
 */
final class TeacherProfessionalProfileManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeacherProfessionalProfileRepository $profiles,
        private readonly LocationRepository $locations,
        private readonly FreshUserLoader $freshUsers,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUsers,
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly ClockInterface $clock,
    ) {
    }

    public function findForUser(User $user): ?TeacherProfessionalProfile
    {
        return $this->profiles->findOneByUser($user);
    }

    public function hasActiveSchoolChain(): bool
    {
        return $this->locations->hasActiveSchoolChain();
    }

    public function save(User $actor, TeacherProfessionalProfileRequest $request): TeacherProfessionalProfile
    {
        $subject = $request->subject;
        if (!$subject instanceof Subject) {
            throw TeacherProfessionalProfileException::invalidSubject();
        }
        $gradeLevels = $this->uniqueGrades($request->gradeLevels);
        $subjectId = $subject->getId();

        try {
            return $this->em->wrapInTransaction(function () use ($actor, $request, $subjectId, $gradeLevels): TeacherProfessionalProfile {
                $lockedActor = $this->freshUsers->findFreshLockedUser($actor->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$lockedActor instanceof User || !$this->canMaintainProfile($lockedActor)) {
                    throw TeacherProfessionalProfileException::unauthorized();
                }

                $subject = $this->lockSubject($subjectId);
                if (!$subject instanceof Subject || SubjectStatus::Active !== $subject->getStatus()) {
                    throw TeacherProfessionalProfileException::invalidSubject();
                }

                $profile = $this->lockProfileForUser($lockedActor);
                $school = $this->resolveSchool($request, $profile);
                $now = \DateTimeImmutable::createFromInterface($this->clock->now());
                if (!$profile instanceof TeacherProfessionalProfile) {
                    $profile = TeacherProfessionalProfile::create($lockedActor, $subject, $gradeLevels, $now, $school);
                    $this->em->persist($profile);
                } else {
                    $profile->apply($subject, $gradeLevels, $school, $now);
                }
                $this->em->flush();

                return $profile;
            });
        } catch (TeacherProfessionalProfileException $exception) {
            throw $exception;
        } catch (UniqueConstraintViolationException|DeadlockException|LockWaitTimeoutException) {
            throw TeacherProfessionalProfileException::conflict();
        }
    }

    private function resolveSchool(TeacherProfessionalProfileRequest $request, ?TeacherProfessionalProfile $profile): ?Location
    {
        if (!$this->locations->hasActiveSchoolChain()) {
            return $profile?->getSchoolLocation();
        }

        $provinceId = $this->requiredUuid($request->provinceId);
        $districtId = $this->requiredUuid($request->districtId);
        $schoolId = $this->requiredUuid($request->schoolId);
        $ids = [$provinceId->toRfc4122() => $provinceId, $districtId->toRfc4122() => $districtId, $schoolId->toRfc4122() => $schoolId];
        if (3 !== \count($ids)) {
            throw TeacherProfessionalProfileException::invalidLocation();
        }
        $ordered = array_values($ids);
        usort($ordered, static fn (Uuid $left, Uuid $right): int => strcmp($left->toRfc4122(), $right->toRfc4122()));
        foreach ($ordered as $id) {
            if (!$this->lockLocation($id) instanceof Location) {
                throw TeacherProfessionalProfileException::invalidLocation();
            }
        }
        $school = $this->locations->findActiveChain($schoolId, $districtId, $provinceId);
        if (!$school instanceof Location) {
            throw TeacherProfessionalProfileException::invalidLocation();
        }

        return $school;
    }

    private function requiredUuid(?string $value): Uuid
    {
        if (null === $value || '' === trim($value) || !Uuid::isValid($value)) {
            throw TeacherProfessionalProfileException::invalidLocation();
        }

        return Uuid::fromString($value);
    }

    private function canMaintainProfile(User $user): bool
    {
        if (!$this->activeVerifiedUsers->isActiveAndVerified($user)) {
            return false;
        }

        return \in_array(UserRole::Teacher->value, $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true);
    }

    /**
     * @param array<array-key, mixed> $gradeLevels
     *
     * @return list<GradeLevel>
     */
    private function uniqueGrades(array $gradeLevels): array
    {
        $unique = [];
        foreach ($gradeLevels as $gradeLevel) {
            if (!$gradeLevel instanceof GradeLevel) {
                throw TeacherProfessionalProfileException::invalidGrades();
            }
            $unique[$gradeLevel->value] = $gradeLevel;
        }
        if ([] === $unique) {
            throw TeacherProfessionalProfileException::invalidGrades();
        }
        ksort($unique);

        return array_values($unique);
    }

    private function lockSubject(Uuid $id): ?Subject
    {
        $query = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Subject::class, 's')
            ->andWhere('s.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof Subject ? $result : null;
    }

    private function lockLocation(Uuid $id): ?Location
    {
        $query = $this->em->createQueryBuilder()
            ->select('location')
            ->from(Location::class, 'location')
            ->andWhere('location.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof Location ? $result : null;
    }

    private function lockProfileForUser(User $user): ?TeacherProfessionalProfile
    {
        $query = $this->em->createQueryBuilder()
            ->select('p')
            ->from(TeacherProfessionalProfile::class, 'p')
            ->andWhere('IDENTITY(p.user) = :userId')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);
        $result = $query->getOneOrNullResult();

        return $result instanceof TeacherProfessionalProfile ? $result : null;
    }
}

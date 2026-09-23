<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\StudentProfileRequest;
use App\Entity\StudentProfile;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Exception\StudentProfileException;
use App\Repository\StudentProfileRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;

/**
 * Owns student profile create/update and onboarding completion invariants.
 */
final class StudentProfileManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StudentProfileRepository $profiles,
        private readonly FreshUserLoader $freshUsers,
        private readonly ClockInterface $clock,
    ) {
    }

    public function findForUser(User $user): ?StudentProfile
    {
        return $this->profiles->findOneByUser($user);
    }

    public function isOnboardingCompleted(User $user): bool
    {
        $profile = $this->findForUser($user);

        return $profile instanceof StudentProfile && $profile->isOnboardingCompleted();
    }

    /**
     * Completes first-login onboarding for the acting student (idempotent on re-submit).
     */
    public function completeOnboarding(User $actor, StudentProfileRequest $request): StudentProfile
    {
        $this->assertStudent($actor);
        $gradeLevel = $this->requireGradeLevel($request);

        try {
            return $this->em->wrapInTransaction(function () use ($actor, $request, $gradeLevel): StudentProfile {
                $lockedActor = $this->freshUsers->findFreshLockedUser($actor->getId(), LockMode::PESSIMISTIC_WRITE);
                if (!$lockedActor instanceof User) {
                    throw StudentProfileException::unauthorized();
                }
                $this->assertStudent($lockedActor);

                $profile = $this->lockProfileForUser($lockedActor);
                $now = $this->clock->now();

                if (!$profile instanceof StudentProfile) {
                    $profile = StudentProfile::createForStudent($lockedActor, $gradeLevel, $now);
                    $this->em->persist($profile);
                }

                $profile->applyProfileDetails(
                    gradeLevel: $gradeLevel,
                    schoolName: $request->schoolName,
                    city: $request->city,
                    learningGoal: $request->learningGoal,
                    now: $now,
                    markOnboardingComplete: true,
                );

                $this->em->flush();

                return $profile;
            });
        } catch (UniqueConstraintViolationException) {
            throw StudentProfileException::conflict();
        }
    }

    /**
     * Updates an already-onboarded student's own profile.
     */
    public function updateProfile(User $actor, StudentProfileRequest $request): StudentProfile
    {
        $this->assertStudent($actor);
        $gradeLevel = $this->requireGradeLevel($request);

        return $this->em->wrapInTransaction(function () use ($actor, $request, $gradeLevel): StudentProfile {
            $lockedActor = $this->freshUsers->findFreshLockedUser($actor->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$lockedActor instanceof User) {
                throw StudentProfileException::unauthorized();
            }
            $this->assertStudent($lockedActor);

            $profile = $this->lockProfileForUser($lockedActor);
            if (!$profile instanceof StudentProfile || !$profile->isOnboardingCompleted()) {
                throw StudentProfileException::notFound();
            }

            $profile->applyProfileDetails(
                gradeLevel: $gradeLevel,
                schoolName: $request->schoolName,
                city: $request->city,
                learningGoal: $request->learningGoal,
                now: $this->clock->now(),
                markOnboardingComplete: false,
            );

            $this->em->flush();

            return $profile;
        });
    }

    private function requireGradeLevel(StudentProfileRequest $request): GradeLevel
    {
        if (!$request->gradeLevel instanceof GradeLevel) {
            throw StudentProfileException::invalidInput('Sınıf seviyesi zorunludur.');
        }

        return $request->gradeLevel;
    }

    private function assertStudent(User $user): void
    {
        if (!\in_array(UserRole::Student->value, $user->getRoles(), true)) {
            throw StudentProfileException::notStudent();
        }
    }

    private function lockProfileForUser(User $user): ?StudentProfile
    {
        $query = $this->em->createQueryBuilder()
            ->select('p')
            ->from(StudentProfile::class, 'p')
            ->andWhere('IDENTITY(p.user) = :userId')
            ->setParameter('userId', $user->getId(), 'uuid')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->setHint(Query::HINT_REFRESH, true);

        $result = $query->getOneOrNullResult();

        return $result instanceof StudentProfile ? $result : null;
    }
}

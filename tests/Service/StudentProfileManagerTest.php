<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\StudentProfileRequest;
use App\Entity\StudentProfile;
use App\Entity\User;
use App\Enum\GradeLevel;
use App\Enum\UserRole;
use App\Exception\StudentProfileException;
use App\Service\StudentProfileManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StudentProfileManagerTest extends KernelTestCase
{
    private StudentProfileManager $manager;
    private UserFactory $factory;
    private UserAccountLifecycle $lifecycle;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = static::getContainer()->get(StudentProfileManager::class);
        $factory = static::getContainer()->get(UserFactory::class);
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(StudentProfileManager::class, $manager);
        self::assertInstanceOf(UserFactory::class, $factory);
        self::assertInstanceOf(UserAccountLifecycle::class, $lifecycle);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->manager = $manager;
        $this->factory = $factory;
        $this->lifecycle = $lifecycle;
        $this->em = $em;
    }

    public function testParentCannotCreateStudentProfile(): void
    {
        $parent = $this->createActive('mgr-parent@example.com', UserRole::Parent);
        $dto = new StudentProfileRequest();
        $dto->gradeLevel = GradeLevel::Grade5;

        $this->expectException(StudentProfileException::class);
        $this->manager->completeOnboarding($parent, $dto);
    }

    public function testCompleteOnboardingIsIdempotent(): void
    {
        $student = $this->createActive('mgr-idem@example.com', UserRole::Student);
        $dto = new StudentProfileRequest();
        $dto->gradeLevel = GradeLevel::Grade2;
        $dto->city = 'İzmir';

        $first = $this->manager->completeOnboarding($student, $dto);
        $dto->gradeLevel = GradeLevel::Grade3;
        $second = $this->manager->completeOnboarding($student, $dto);

        self::assertSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertSame(GradeLevel::Grade3, $second->getGradeLevel());
        self::assertTrue($second->isOnboardingCompleted());

        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(StudentProfile::class, 'p')
            ->andWhere('IDENTITY(p.user) = :uid')
            ->setParameter('uid', $student->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
        self::assertSame(1, $count);
    }

    public function testUpdateRequiresCompletedProfile(): void
    {
        $student = $this->createActive('mgr-update@example.com', UserRole::Student);
        $dto = new StudentProfileRequest();
        $dto->gradeLevel = GradeLevel::Grade1;

        $this->expectException(StudentProfileException::class);
        $this->manager->updateProfile($student, $dto);
    }

    private function createActive(string $email, UserRole $role): User
    {
        $user = $this->factory->createAndPersist($email, 'Guclu-Parola-123!', 'Ali', 'Demir', $role);
        $this->lifecycle->markEmailVerifiedAndActivate($user);

        return $user;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->em->getConnection()->createSchemaManager()->tablesExist(['student_profiles'])) {
                $this->em->getConnection()->executeStatement('DELETE FROM student_profiles');
            }
            if ($this->em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
                $this->em->getConnection()->executeStatement('DELETE FROM users');
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}

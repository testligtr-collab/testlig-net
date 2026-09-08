<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\UserRole;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use App\Service\PasswordManager;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final class PasswordManagerTest extends KernelTestCase
{
    public function testResetRemovesTokenSoItCannotBeReused(): void
    {
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        /** @var ResetPasswordRequestRepository $repo */
        $repo = static::getContainer()->get(ResetPasswordRequestRepository::class);
        /** @var ResetPasswordHelperInterface $helper */
        $helper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        /** @var PasswordManager $manager */
        $manager = static::getContainer()->get(PasswordManager::class);

        $user = $factory->createAndPersist('pm-reuse@example.com', 'Guclu-Parola-123!', 'Pm', 'User', UserRole::Student);
        $lifecycle->markEmailVerifiedAndActivate($user);
        $repo->removeRequests($user);
        $token = $helper->generateResetToken($user)->getToken();
        self::assertNotNull($repo->getMostRecentNonExpiredRequestDate($user));

        $manager->resetPassword($token, 'Yeni-Guclu-Parola-789!');
        self::assertNull($repo->getMostRecentNonExpiredRequestDate($user));

        $this->expectException(ResetPasswordExceptionInterface::class);
        $helper->validateTokenAndFetchUser($token);
    }

    public function testResetPasswordChangedAtIsStrictlyMonotonicWithoutSleep(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var UserFactory $factory */
        $factory = $container->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = $container->get(UserAccountLifecycle::class);
        /** @var ResetPasswordRequestRepository $repo */
        $repo = $container->get(ResetPasswordRequestRepository::class);
        /** @var ResetPasswordHelperInterface $helper */
        $helper = $container->get(ResetPasswordHelperInterface::class);
        /** @var PasswordManager $manager */
        $manager = $container->get(PasswordManager::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = $factory->createAndPersist('pm-mono-reset@example.com', 'Guclu-Parola-123!', 'Pm', 'Mono', UserRole::Student);
        $lifecycle->markEmailVerifiedAndActivate($user);
        // Align changed-at with "now" so reset in the same second must advance +1s.
        $user->setPassword($user->getPassword(), new \DateTimeImmutable('now'));
        $users->save($user);
        $before = $user->getPasswordChangedAt();

        $repo->removeRequests($user);
        $token = $helper->generateResetToken($user)->getToken();
        $manager->resetPassword($token, 'Yeni-Guclu-Parola-789!');

        $em->clear();
        $reloaded = $users->findOneByNormalizedEmail('pm-mono-reset@example.com');
        self::assertNotNull($reloaded);
        self::assertGreaterThan($before, $reloaded->getPasswordChangedAt());
    }

    public function testChangePasswordChangedAtIsStrictlyMonotonicWithoutSleep(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var UserFactory $factory */
        $factory = $container->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = $container->get(UserAccountLifecycle::class);
        /** @var PasswordManager $manager */
        $manager = $container->get(PasswordManager::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = $factory->createAndPersist('pm-mono-change@example.com', 'Guclu-Parola-123!', 'Pm', 'Change', UserRole::Student);
        $lifecycle->markEmailVerifiedAndActivate($user);
        $user->setPassword($user->getPassword(), new \DateTimeImmutable('now'));
        $users->save($user);
        $before = $user->getPasswordChangedAt();

        $manager->changePassword($user, 'Guclu-Parola-123!', 'Yeni-Guclu-Parola-789!');

        $em->clear();
        $reloaded = $users->findOneByNormalizedEmail('pm-mono-change@example.com');
        self::assertNotNull($reloaded);
        self::assertGreaterThan($before, $reloaded->getPasswordChangedAt());
    }

    public function testSecondResetWithSameTokenFailsAfterFirstConsumption(): void
    {
        self::bootKernel();
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        /** @var ResetPasswordRequestRepository $repo */
        $repo = static::getContainer()->get(ResetPasswordRequestRepository::class);
        /** @var ResetPasswordHelperInterface $helper */
        $helper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        /** @var PasswordManager $manager */
        $manager = static::getContainer()->get(PasswordManager::class);

        $user = $factory->createAndPersist('pm-race@example.com', 'Guclu-Parola-123!', 'Pm', 'Race', UserRole::Student);
        $lifecycle->markEmailVerifiedAndActivate($user);
        $repo->removeRequests($user);
        $token = $helper->generateResetToken($user)->getToken();

        $manager->resetPassword($token, 'Yeni-Guclu-Parola-111!');
        $this->expectException(\Throwable::class);
        $manager->resetPassword($token, 'Yeni-Guclu-Parola-222!');
    }

    protected function tearDown(): void
    {
        try {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            if ($em->getConnection()->createSchemaManager()->tablesExist(['reset_password_requests'])) {
                $em->getConnection()->executeStatement('DELETE FROM reset_password_requests');
            }
            if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
                $em->getConnection()->executeStatement('DELETE FROM users');
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}

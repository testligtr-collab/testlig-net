<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\UserRole;
use App\Repository\ResetPasswordRequestRepository;
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

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        if ($em->getConnection()->createSchemaManager()->tablesExist(['reset_password_requests'])) {
            $em->getConnection()->executeStatement('DELETE FROM reset_password_requests');
        }
        if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
            $em->getConnection()->executeStatement('DELETE FROM users');
        }
        parent::tearDown();
    }
}

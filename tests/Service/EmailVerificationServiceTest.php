<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\EmailVerificationException;
use App\Repository\UserRepository;
use App\Service\EmailVerificationService;
use App\Service\UserAccountLifecycle;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class EmailVerificationServiceTest extends KernelTestCase
{
    public function testValidSignatureActivatesUser(): void
    {
        self::bootKernel();
        $user = $this->createPendingUser('verify-ok@example.com');
        $request = $this->signedRequestFor($user);

        /** @var EmailVerificationService $service */
        $service = static::getContainer()->get(EmailVerificationService::class);
        $verified = $service->verifyFromRequest($request, $user->getId());

        self::assertSame(UserStatus::Active, $verified->getStatus());
        self::assertNotNull($verified->getEmailVerifiedAt());
    }

    public function testActiveUserValidReplayIsIdempotent(): void
    {
        self::bootKernel();
        $user = $this->createPendingUser('verify-replay@example.com');
        $request = $this->signedRequestFor($user);

        /** @var EmailVerificationService $service */
        $service = static::getContainer()->get(EmailVerificationService::class);
        $service->verifyFromRequest($request, $user->getId());
        $again = $service->verifyFromRequest($request, $user->getId());

        self::assertSame(UserStatus::Active, $again->getStatus());
        self::assertNotNull($again->getEmailVerifiedAt());
    }

    public function testTamperedSignatureIsRejectedForPendingUser(): void
    {
        self::bootKernel();
        $user = $this->createPendingUser('verify-bad@example.com');
        $request = $this->signedRequestFor($user);
        $tampered = Request::create($request->getUri().'tampered');

        /** @var EmailVerificationService $service */
        $service = static::getContainer()->get(EmailVerificationService::class);
        $this->expectException(EmailVerificationException::class);
        $service->verifyFromRequest($tampered, $user->getId());
    }

    public function testTamperedSignatureIsRejectedForActiveUser(): void
    {
        self::bootKernel();
        $user = $this->createActiveUser('verify-active-tamper@example.com');
        $request = $this->signedRequestFor($user);
        $tampered = Request::create($request->getUri().'x');

        /** @var EmailVerificationService $service */
        $service = static::getContainer()->get(EmailVerificationService::class);
        $this->expectException(EmailVerificationException::class);
        $service->verifyFromRequest($tampered, $user->getId());
    }

    public function testExpiredSignatureIsRejectedWithoutSleep(): void
    {
        self::bootKernel();
        $user = $this->createPendingUser('verify-expired@example.com');
        $request = $this->expiredSignedRequestFor($user);

        /** @var EmailVerificationService $service */
        $service = static::getContainer()->get(EmailVerificationService::class);
        $this->expectException(EmailVerificationException::class);
        $service->verifyFromRequest($request, $user->getId());
    }

    public function testSignatureCannotBeUsedForAnotherUser(): void
    {
        self::bootKernel();
        $userA = $this->createPendingUser('verify-a@example.com');
        $userB = $this->createPendingUser('verify-b@example.com');
        $request = $this->signedRequestFor($userA);

        /** @var EmailVerificationService $service */
        $service = static::getContainer()->get(EmailVerificationService::class);
        $this->expectException(EmailVerificationException::class);
        $service->verifyFromRequest($request, $userB->getId());
    }

    public function testSuspendedUserCannotBeActivatedEvenWithValidSignature(): void
    {
        self::bootKernel();
        $user = $this->createUserWithStatus('verify-suspended@example.com', UserStatus::Suspended);
        $request = $this->signedRequestFor($user);

        /** @var EmailVerificationService $service */
        $service = static::getContainer()->get(EmailVerificationService::class);
        try {
            $service->verifyFromRequest($request, $user->getId());
            self::fail('Expected EmailVerificationException');
        } catch (EmailVerificationException) {
        }

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $reloaded = $users->findOneById($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(UserStatus::Suspended, $reloaded->getStatus());
    }

    public function testArchivedUserCannotBeActivatedEvenWithValidSignature(): void
    {
        self::bootKernel();
        $user = $this->createUserWithStatus('verify-archived@example.com', UserStatus::Archived);
        $request = $this->signedRequestFor($user);

        /** @var EmailVerificationService $service */
        $service = static::getContainer()->get(EmailVerificationService::class);
        try {
            $service->verifyFromRequest($request, $user->getId());
            self::fail('Expected EmailVerificationException');
        } catch (EmailVerificationException) {
        }

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $reloaded = $users->findOneById($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(UserStatus::Archived, $reloaded->getStatus());
    }

    private function createPendingUser(string $email): User
    {
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);

        return $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Verify', 'User', UserRole::Student);
    }

    private function createActiveUser(string $email): User
    {
        $user = $this->createPendingUser($email);
        /** @var UserAccountLifecycle $lifecycle */
        $lifecycle = static::getContainer()->get(UserAccountLifecycle::class);
        $lifecycle->markEmailVerifiedAndActivate($user);

        return $user;
    }

    private function createUserWithStatus(string $email, UserStatus $status): User
    {
        $user = $this->createActiveUser($email);
        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $user->transitionTo($status);
        $users->save($user);

        return $user;
    }

    private function signedRequestFor(User $user): Request
    {
        /** @var VerifyEmailHelperInterface $helper */
        $helper = static::getContainer()->get(VerifyEmailHelperInterface::class);
        $signature = $helper->generateSignature(
            'app_verify_email',
            $user->getId()->toRfc4122(),
            $user->getEmail(),
            ['id' => $user->getId()->toRfc4122()],
        );

        return Request::create($signature->getSignedUrl());
    }

    /**
     * Lifetime 0 makes expires <= time() immediately (no sleep).
     */
    private function expiredSignedRequestFor(User $user): Request
    {
        /** @var VerifyEmailHelperInterface $helper */
        $helper = static::getContainer()->get(VerifyEmailHelperInterface::class);
        $lifetime = new \ReflectionProperty($helper, 'lifetime');
        $original = $lifetime->getValue($helper);
        $lifetime->setValue($helper, 0);

        try {
            return $this->signedRequestFor($user);
        } finally {
            $lifetime->setValue($helper, $original);
        }
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        if ($em->getConnection()->createSchemaManager()->tablesExist(['users'])) {
            $em->getConnection()->executeStatement('DELETE FROM users');
        }
        parent::tearDown();
    }
}

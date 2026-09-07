<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\EmailVerificationException;
use App\Service\EmailVerificationService;
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

        $again = $service->verifyFromRequest($request, $user->getId());
        self::assertSame(UserStatus::Active, $again->getStatus());
    }

    public function testTamperedSignatureIsRejected(): void
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

    private function createPendingUser(string $email): User
    {
        /** @var UserFactory $factory */
        $factory = static::getContainer()->get(UserFactory::class);

        return $factory->createAndPersist($email, 'Guclu-Parola-123!', 'Verify', 'User', UserRole::Student);
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

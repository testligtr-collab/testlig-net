<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Sends signed verification messages. Never logs the signed URL.
 */
final class EmailVerificationMailer
{
    public function __construct(
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly MailerInterface $mailer,
        private readonly UserRepository $users,
        private readonly EmailNormalizer $emailNormalizer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM_EMAIL)%')]
        private readonly string $fromEmail,
        #[Autowire('%env(MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {
    }

    public function sendVerificationEmail(User $user): void
    {
        $signature = $this->createSignature($user);
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to(new Address($user->getEmail()))
            ->subject('Testlig e-posta doğrulama')
            ->htmlTemplate('email/verification.html.twig')
            ->context([
                'user' => $user,
                'signedUrl' => $signature->getSignedUrl(),
                'expiresAtMessageKey' => $signature->getExpirationMessageKey(),
                'expiresAtMessageData' => $signature->getExpirationMessageData(),
            ]);

        $this->mailer->send($email);
        $this->logger->info('Verification email queued/sent.', [
            'user_id' => $user->getId()->toRfc4122(),
        ]);
    }

    /**
     * Always returns without revealing whether the address exists.
     */
    public function requestResend(string $email): void
    {
        try {
            $normalized = $this->emailNormalizer->normalize($email);
        } catch (\InvalidArgumentException) {
            return;
        }

        $user = $this->users->findOneByNormalizedEmail($normalized);
        if (!$user instanceof User) {
            return;
        }

        if (UserStatus::PendingVerification !== $user->getStatus()) {
            return;
        }

        if (null !== $user->getEmailVerifiedAt()) {
            return;
        }

        $this->sendVerificationEmail($user);
    }

    private function createSignature(User $user): VerifyEmailSignatureComponents
    {
        return $this->verifyEmailHelper->generateSignature(
            'app_verify_email',
            $user->getId()->toRfc4122(),
            $user->getEmail(),
            ['id' => $user->getId()->toRfc4122()],
        );
    }
}

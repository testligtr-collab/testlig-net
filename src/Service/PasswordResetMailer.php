<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

/**
 * Sends password-reset messages. Never logs the token or signed URL.
 */
final class PasswordResetMailer implements PasswordResetNotifierInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM_EMAIL)%')]
        private readonly string $fromEmail,
        #[Autowire('%env(MAILER_FROM_NAME)%')]
        private readonly string $fromName,
        #[Autowire('%env(int:RESET_PASSWORD_LIFETIME)%')]
        private readonly int $resetPasswordLifetime,
    ) {
    }

    public function sendResetEmail(User $user, ResetPasswordToken $resetToken): void
    {
        $resetUrl = $this->urlGenerator->generate(
            'app_reset_password_token',
            ['token' => $resetToken->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to(new Address($user->getEmail()))
            ->subject('Testlig parola yenileme')
            ->htmlTemplate('email/password_reset.html.twig')
            ->textTemplate('email/password_reset.txt.twig')
            ->context([
                'user' => $user,
                'resetUrl' => $resetUrl,
                'tokenLifetimeMinutes' => max(1, (int) floor($this->resetPasswordLifetime / 60)),
            ]);

        $this->mailer->send($email);
        $this->logger->info('Password reset email queued/sent.', [
            'user_id' => $user->getId()->toRfc4122(),
        ]);
    }
}

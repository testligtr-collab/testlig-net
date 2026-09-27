<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends the student-invite link. The URL is built from DEFAULT_URI, not the request Host header, and is not logged.
 */
final class InstitutionStudentInviteMailer implements InstitutionStudentInviteSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM_EMAIL)%')]
        private readonly string $fromEmail,
        #[Autowire('%env(MAILER_FROM_NAME)%')]
        private readonly string $fromName,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
    ) {
    }

    public function send(
        string $recipientEmail,
        string $institutionName,
        string $classroomName,
        \DateTimeImmutable $expiresAt,
        string $plainToken,
    ): void {
        $path = $this->urls->generate('app_student_invite_open', ['token' => $plainToken]);
        $link = rtrim($this->defaultUri, '/').$path;
        $zone = new \DateTimeZone('Europe/Istanbul');
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to(new Address($recipientEmail))
            ->subject('Testlig öğrenci daveti')
            ->htmlTemplate('email/institution_student_invite.html.twig')
            ->context([
                'institutionName' => $institutionName,
                'classroomName' => $classroomName,
                'expiresAtLabel' => $expiresAt->setTimezone($zone)->format('d.m.Y H:i'),
                'acceptUrl' => $link,
            ]);
        $this->mailer->send($email);
        $this->logger->info('Institution student invite email queued/sent.');
    }
}

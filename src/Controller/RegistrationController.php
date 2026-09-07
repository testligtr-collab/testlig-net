<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegistrationRequest;
use App\Exception\RegistrationFailedException;
use App\Form\RegistrationFormType;
use App\Form\ResendVerificationFormType;
use App\Service\EmailVerificationSenderInterface;
use App\Service\RegistrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/kayit', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request, RegistrationService $registrationService): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_account');
        }

        $dto = new RegistrationRequest();
        $form = $this->createForm(RegistrationFormType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $registrationService->register($dto);
                $request->getSession()->set('registration_email_hint', $dto->email);

                return $this->redirectToRoute('app_register_check_email');
            } catch (RegistrationFailedException) {
                $this->addFlash(
                    'error',
                    'Kayıt tamamlanamadı. Bilgilerinizi kontrol edin veya farklı bir e-posta deneyin.'
                );
            }
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/kayit/eposta-kontrol', name: 'app_register_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_account');
        }

        return $this->render('security/check_email.html.twig');
    }

    #[Route('/kayit/dogrulama-yeniden', name: 'app_resend_verification', methods: ['GET', 'POST'])]
    public function resendVerification(
        Request $request,
        EmailVerificationSenderInterface $mailer,
        #[Autowire(service: 'limiter.email_verification_resend')]
        RateLimiterFactory $emailVerificationResendLimiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_account');
        }

        $form = $this->createForm(ResendVerificationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $emailVerificationResendLimiter->create($request->getClientIp() ?? 'unknown');
            if (!$limiter->consume(1)->isAccepted()) {
                throw new TooManyRequestsHttpException(null, 'Çok fazla istek. Lütfen daha sonra tekrar deneyin.');
            }

            /** @var array{email?: string} $data */
            $data = $form->getData() ?? [];
            $mailer->requestResend((string) ($data['email'] ?? ''));

            $this->addFlash(
                'success',
                'Eğer bu e-posta ile doğrulanmayı bekleyen bir hesap varsa, doğrulama bağlantısı gönderildi.'
            );

            return $this->redirectToRoute('app_resend_verification');
        }

        return $this->render('security/resend_verification.html.twig', [
            'resendForm' => $form,
        ]);
    }
}

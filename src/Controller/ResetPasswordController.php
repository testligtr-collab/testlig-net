<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ForgotPasswordRequest;
use App\Dto\ResetPasswordRequestData;
use App\Exception\PasswordResetFailedException;
use App\Form\ForgotPasswordFormType;
use App\Form\ResetPasswordFormType;
use App\Service\EmailNormalizer;
use App\Service\PasswordManager;
use App\Service\RateLimitKeyHasher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;

final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    private const GENERIC_CHECK_EMAIL_MESSAGE = 'Eğer bu e-posta ile kullanılabilir bir hesap varsa, parola yenileme bağlantısı gönderildi.';

    public function __construct(
        private readonly PasswordManager $passwordManager,
        private readonly EmailNormalizer $emailNormalizer,
        private readonly RateLimitKeyHasher $rateLimitKeyHasher,
        #[Autowire(service: 'limiter.password_reset_ip')]
        private readonly RateLimiterFactory $passwordResetIpLimiter,
        #[Autowire(service: 'limiter.password_reset_email')]
        private readonly RateLimiterFactory $passwordResetEmailLimiter,
        #[Autowire(service: 'limiter.password_reset_token')]
        private readonly RateLimiterFactory $passwordResetTokenLimiter,
    ) {
    }

    #[Route('/sifremi-unuttum', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_account');
        }

        $dto = new ForgotPasswordRequest();
        $form = $this->createForm(ForgotPasswordFormType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->enforceRequestRateLimits($request, $dto->email);
            $this->passwordManager->requestReset($dto->email);

            return $this->redirectToRoute('app_forgot_password_check_email');
        }

        $response = $this->render('security/forgot_password.html.twig', [
            'requestForm' => $form,
        ]);
        $this->applyNoReferrer($response);

        return $response;
    }

    #[Route('/sifremi-unuttum/eposta-kontrol', name: 'app_forgot_password_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_account');
        }

        $response = $this->render('security/forgot_password_check_email.html.twig', [
            'message' => self::GENERIC_CHECK_EMAIL_MESSAGE,
            'tokenLifetime' => $this->passwordManager->getTokenLifetime(),
        ]);
        $this->applyNoReferrer($response);

        return $response;
    }

    #[Route('/sifre-yenile/{token}', name: 'app_reset_password_token', methods: ['GET'])]
    public function receiveToken(Request $request, string $token): Response
    {
        $this->enforceTokenRateLimit($request);

        try {
            $this->passwordManager->validateTokenAndFetchActiveUser($token);
        } catch (PasswordResetFailedException) {
            $this->addFlash('error', PasswordResetFailedException::invalidToken()->getMessage());

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $this->storeTokenInSession($token);

        return $this->redirectToRoute('app_reset_password');
    }

    #[Route('/sifre-yenile', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_account');
        }

        $token = $this->getTokenFromSession();
        if (null === $token || '' === $token) {
            $this->addFlash('error', PasswordResetFailedException::invalidToken()->getMessage());

            return $this->redirectToRoute('app_forgot_password_request');
        }

        try {
            $this->passwordManager->validateTokenAndFetchActiveUser($token);
        } catch (PasswordResetFailedException) {
            $this->cleanSessionAfterReset();
            $this->addFlash('error', PasswordResetFailedException::invalidToken()->getMessage());

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $dto = new ResetPasswordRequestData();
        $form = $this->createForm(ResetPasswordFormType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->passwordManager->resetPassword($token, $dto->plainPassword);
                $this->cleanSessionAfterReset();
                $this->addFlash('success', 'Parolanız güncellendi. Yeni parolanızla giriş yapabilirsiniz.');

                return $this->redirectToRoute('app_login');
            } catch (PasswordResetFailedException $exception) {
                if ('Yeni parola mevcut parolanızdan farklı olmalıdır.' === $exception->getMessage()) {
                    $this->addFlash('error', $exception->getMessage());
                } else {
                    $this->cleanSessionAfterReset();
                    $this->addFlash('error', PasswordResetFailedException::invalidToken()->getMessage());

                    return $this->redirectToRoute('app_forgot_password_request');
                }
            }
        }

        $response = $this->render('security/reset_password.html.twig', [
            'resetForm' => $form,
        ]);
        $this->applyNoReferrer($response);

        return $response;
    }

    private function enforceRequestRateLimits(Request $request, string $email): void
    {
        $ipLimiter = $this->passwordResetIpLimiter->create($request->getClientIp() ?? 'unknown');
        $ipLimit = $ipLimiter->consume(1);
        if (!$ipLimit->isAccepted()) {
            $seconds = max(0, $ipLimit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($seconds, 'Çok fazla istek. Lütfen daha sonra tekrar deneyin.');
        }

        try {
            $normalized = $this->emailNormalizer->normalize($email);
        } catch (\InvalidArgumentException) {
            return;
        }

        $emailKey = $this->rateLimitKeyHasher->hashEmail($normalized);
        $emailLimiter = $this->passwordResetEmailLimiter->create($emailKey);
        $emailLimit = $emailLimiter->consume(1);
        if (!$emailLimit->isAccepted()) {
            $seconds = max(0, $emailLimit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($seconds, 'Çok fazla istek. Lütfen daha sonra tekrar deneyin.');
        }
    }

    private function enforceTokenRateLimit(Request $request): void
    {
        $limiter = $this->passwordResetTokenLimiter->create($request->getClientIp() ?? 'unknown');
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $seconds = max(0, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($seconds, 'Çok fazla istek. Lütfen daha sonra tekrar deneyin.');
        }
    }

    private function applyNoReferrer(Response $response): void
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
    }
}

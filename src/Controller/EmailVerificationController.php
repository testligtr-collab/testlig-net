<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\EmailVerificationException;
use App\Service\EmailVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class EmailVerificationController extends AbstractController
{
    #[Route('/dogrula/eposta', name: 'app_verify_email', methods: ['GET'])]
    public function verify(Request $request, EmailVerificationService $verificationService): Response
    {
        $id = (string) $request->query->get('id', '');
        if ('' === $id || !Uuid::isValid($id)) {
            $this->addFlash('error', 'Doğrulama bağlantısı geçersiz veya süresi dolmuş.');

            return $this->redirectToRoute('app_login');
        }

        try {
            $verificationService->verifyFromRequest($request, Uuid::fromString($id));
            $this->addFlash('success', 'E-posta adresiniz doğrulandı. Şimdi giriş yapabilirsiniz.');
        } catch (EmailVerificationException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_login');
    }
}

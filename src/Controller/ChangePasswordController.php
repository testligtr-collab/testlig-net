<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ChangePasswordRequest;
use App\Entity\User;
use App\Enum\PasswordChangeFailureReason;
use App\Exception\PasswordChangeFailedException;
use App\Form\ChangePasswordFormType;
use App\Service\PasswordManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ChangePasswordController extends AbstractController
{
    #[Route('/hesabim/sifre-degistir', name: 'app_account_change_password', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(
        Request $request,
        PasswordManager $passwordManager,
        TokenStorageInterface $tokenStorage,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $dto = new ChangePasswordRequest();
        $form = $this->createForm(ChangePasswordFormType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $passwordManager->changePassword($user, $dto->currentPassword, $dto->newPassword);
                // End this session; EquatableInterface invalidates other sessions after password change.
                $tokenStorage->setToken(null);
                $request->getSession()->invalidate();
                $this->addFlash('success', 'Parolanız güncellendi. Lütfen yeni parolanızla tekrar giriş yapın.');

                return $this->redirectToRoute('app_login');
            } catch (PasswordChangeFailedException $exception) {
                $message = match ($exception->getReason()) {
                    PasswordChangeFailureReason::SameAsCurrent => 'Yeni parola mevcut parolanızdan farklı olmalıdır.',
                    PasswordChangeFailureReason::Conflict => 'İşlem şu anda tamamlanamadı. Lütfen daha sonra tekrar deneyin.',
                    default => 'Parola değiştirilemedi. Bilgilerinizi kontrol edip tekrar deneyin.',
                };
                $this->addFlash('error', $message);
            }
        }

        return $this->render('account/change_password.html.twig', [
            'changePasswordForm' => $form,
        ]);
    }
}

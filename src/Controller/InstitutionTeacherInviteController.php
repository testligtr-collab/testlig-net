<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\InstitutionTeacherInviteException;
use App\Service\InstitutionTeacherInvitationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class InstitutionTeacherInviteController extends AbstractController
{
    use TargetPathTrait;

    private const SESSION_TOKEN = 'institution_teacher_invite_token';

    public function __construct(
        private readonly InstitutionTeacherInvitationManager $invites,
    ) {
    }

    #[Route('/davet/ogretmen/{token}', name: 'app_teacher_invite_open', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{43}'])]
    public function open(Request $request, string $token): Response
    {
        if (null === $this->invites->preview($token)) {
            throw new NotFoundHttpException('Not Found');
        }
        $request->getSession()->set(self::SESSION_TOKEN, $token);
        $this->saveTargetPath($request->getSession(), 'main', $this->generateUrl('app_teacher_invite_review'));

        return $this->redirectToRoute('app_teacher_invite_review');
    }

    #[Route('/davet/ogretmen', name: 'app_teacher_invite_review', methods: ['GET'])]
    public function review(Request $request): Response
    {
        $token = $request->getSession()->get(self::SESSION_TOKEN);
        $preview = \is_string($token) ? $this->invites->preview($token) : null;
        if (null === $preview) {
            $request->getSession()->remove(self::SESSION_TOKEN);

            return $this->render('institution/teacher_invite_accept.html.twig', [
                'preview' => null,
            ]);
        }
        $user = $this->getUser();

        return $this->render('institution/teacher_invite_accept.html.twig', [
            'preview' => $preview,
            'authenticated' => $user instanceof User,
        ]);
    }

    #[Route('/davet/ogretmen/kabul', name: 'app_teacher_invite_accept', methods: ['POST'])]
    public function accept(Request $request): Response
    {
        return $this->decide($request, true);
    }

    #[Route('/davet/ogretmen/ret', name: 'app_teacher_invite_decline', methods: ['POST'])]
    public function decline(Request $request): Response
    {
        return $this->decide($request, false);
    }

    private function decide(Request $request, bool $accept): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $csrf = $accept ? 'institution_teacher_invite_accept' : 'institution_teacher_invite_decline';
        if (!$this->isCsrfTokenValid($csrf, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $token = $request->getSession()->get(self::SESSION_TOKEN);
        if (!\is_string($token) || '' === $token) {
            throw new NotFoundHttpException('Not Found');
        }
        try {
            if ($accept) {
                $this->invites->accept($user, $token);
                $request->getSession()->remove(self::SESSION_TOKEN);
                $this->addFlash('success', 'Öğretmen daveti kabul edildi.');

                return $this->redirectToRoute('app_account');
            }
            $this->invites->decline($user, $token);
            $request->getSession()->remove(self::SESSION_TOKEN);
            $this->addFlash('success', 'Davet reddedildi.');

            return $this->redirectToRoute('app_account');
        } catch (InstitutionTeacherInviteException $exception) {
            $this->addFlash('error', match ($exception->getReason()) {
                \App\Enum\InstitutionTeacherInviteFailureReason::AccountNotReady => 'Daveti kabul etmek için hesabınızın doğrulanmış ve aktif olması gerekir.',
                \App\Enum\InstitutionTeacherInviteFailureReason::RateLimited => 'Çok fazla deneme. Bir süre sonra yeniden deneyin.',
                default => 'Davet kabul edilemedi.',
            });

            return $this->redirectToRoute('app_teacher_invite_review');
        }
    }
}

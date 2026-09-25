<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\AdminAuthorization;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AccountController extends AbstractController
{
    public function __construct(
        private readonly AdminAuthorization $adminAuthorization,
    ) {
    }

    #[Route('/hesabim', name: 'app_account', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('account/show.html.twig', [
            'user' => $user,
            'learning_contents_href' => $this->adminAuthorization->canViewLearningContentWorkspace($user)
                ? $this->generateUrl('app_admin_learning_contents')
                : null,
            'questions_href' => $this->adminAuthorization->canViewQuestionBank($user)
                ? $this->generateUrl('app_admin_questions')
                : null,
            'tests_href' => $this->adminAuthorization->canViewTestBank($user)
                ? $this->generateUrl('app_admin_tests')
                : null,
        ]);
    }
}

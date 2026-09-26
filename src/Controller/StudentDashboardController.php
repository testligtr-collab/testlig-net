<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\StudentProfileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/ogrenci')]
#[IsGranted('ROLE_STUDENT')]
final class StudentDashboardController extends AbstractController
{
    public function __construct(
        private readonly StudentProfileManager $profiles,
    ) {
    }

    #[Route('', name: 'app_student_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $profile = $this->profiles->findForUser($user);
        if (null === $profile || !$profile->isOnboardingCompleted()) {
            return $this->redirectToRoute('app_student_onboarding');
        }

        return $this->render('student/dashboard.html.twig', [
            'user' => $user,
            'profile' => $profile,
            'quickLinks' => [
                [
                    'label' => 'Dersler',
                    'href' => $this->generateUrl('app_student_courses'),
                    'soon' => false,
                ],
                [
                    'label' => 'Testler',
                    'href' => $this->generateUrl('app_student_tests'),
                    'soon' => false,
                ],
                [
                    'label' => 'Öğrenme araçları',
                    'href' => null,
                    'soon' => true,
                ],
            ],
        ]);
    }
}

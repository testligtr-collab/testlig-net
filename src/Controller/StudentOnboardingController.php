<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\StudentProfileRequest;
use App\Entity\User;
use App\Exception\StudentProfileException;
use App\Form\StudentProfileFormType;
use App\Service\StudentProfileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/ogrenci')]
#[IsGranted('ROLE_STUDENT')]
final class StudentOnboardingController extends AbstractController
{
    public function __construct(
        private readonly StudentProfileManager $profiles,
    ) {
    }

    #[Route('/kurulum', name: 'app_student_onboarding', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->requireStudentUser();

        $dto = new StudentProfileRequest();
        $existing = $this->profiles->findForUser($user);
        if (null !== $existing) {
            $dto = StudentProfileRequest::fromProfile($existing);
        }

        $form = $this->createForm(StudentProfileFormType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->profiles->completeOnboarding($user, $dto);
                $this->addFlash('success', 'Profilin hazır. Öğrenmeye devam edebilirsin.');

                return $this->redirectToRoute('app_student_dashboard');
            } catch (StudentProfileException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('student/onboarding.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    private function requireStudentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}

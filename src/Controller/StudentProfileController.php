<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\StudentProfileRequest;
use App\Entity\User;
use App\Exception\StudentProfileException;
use App\Form\StudentProfileFormType;
use App\Service\ParentLinkQuery;
use App\Service\ParentStudentLinkCodeManager;
use App\Service\StudentProfileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/ogrenci')]
#[IsGranted('ROLE_STUDENT')]
final class StudentProfileController extends AbstractController
{
    public function __construct(
        private readonly StudentProfileManager $profiles,
        private readonly ParentLinkQuery $parentLinks,
    ) {
    }

    #[Route('/profil', name: 'app_student_profile', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $profile = $this->profiles->findForUser($user);
        if (null === $profile || !$profile->isOnboardingCompleted()) {
            return $this->redirectToRoute('app_student_onboarding');
        }

        $dto = StudentProfileRequest::fromProfile($profile);
        $form = $this->createForm(StudentProfileFormType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->profiles->updateProfile($user, $dto);
                $this->addFlash('success', 'Profil bilgilerin güncellendi.');

                return $this->redirectToRoute('app_student_profile');
            } catch (StudentProfileException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        $issuedCode = $request->getSession()->get(ParentStudentLinkCodeManager::DISPLAY_SESSION_KEY);
        $request->getSession()->remove(ParentStudentLinkCodeManager::DISPLAY_SESSION_KEY);

        return $this->render('student/profile.html.twig', [
            'form' => $form,
            'user' => $user,
            'profile' => $profile,
            'parent_links' => $this->parentLinks->parentsForStudent($user),
            'open_parent_code' => $this->parentLinks->openCodeForStudent($user),
            'issued_parent_code' => \is_string($issuedCode) ? $issuedCode : null,
        ]);
    }
}

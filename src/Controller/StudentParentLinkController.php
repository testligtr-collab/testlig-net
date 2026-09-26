<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\ParentStudentLinkException;
use App\Service\ParentLinkQuery;
use App\Service\ParentStudentLinkCodeManager;
use App\Service\StudentProfileManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/ogrenci/profil/veli')]
#[IsGranted('ROLE_STUDENT')]
final class StudentParentLinkController extends AbstractController
{
    public function __construct(
        private readonly ParentStudentLinkCodeManager $links,
        private readonly ParentLinkQuery $query,
        private readonly StudentProfileManager $profiles,
    ) {
    }

    #[Route('/kod', name: 'app_student_parent_code_issue', methods: ['POST'])]
    public function issue(Request $request): Response
    {
        $student = $this->student();
        if (!$this->profiles->isOnboardingCompleted($student)) {
            return $this->redirectToRoute('app_student_onboarding');
        }
        if (!$this->isCsrfTokenValid('student_parent_code_issue', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $issued = $this->links->issue($student);
        } catch (ParentStudentLinkException $exception) {
            return $this->fail($exception);
        }

        $request->getSession()->set(ParentStudentLinkCodeManager::DISPLAY_SESSION_KEY, $issued->displayCode);
        $this->addFlash('success', 'Bağlantı kodu oluşturuldu. Kod 15 dakika geçerlidir ve bir kez kullanılabilir.');

        return $this->redirectToRoute('app_student_profile');
    }

    #[Route('/kod/iptal', name: 'app_student_parent_code_revoke', methods: ['POST'])]
    public function revokeCode(Request $request): Response
    {
        $student = $this->student();
        if (!$this->profiles->isOnboardingCompleted($student)) {
            return $this->redirectToRoute('app_student_onboarding');
        }
        if (!$this->isCsrfTokenValid('student_parent_code_revoke', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->links->revokeOpenCode($student);
            $this->addFlash('success', 'Bağlantı kodu iptal edildi.');
        } catch (ParentStudentLinkException $exception) {
            return $this->fail($exception);
        }

        return $this->redirectToRoute('app_student_profile');
    }

    #[Route('/kaldir', name: 'app_student_parent_link_revoke', methods: ['POST'])]
    public function revokeLink(Request $request): Response
    {
        $student = $this->student();
        if (!$this->profiles->isOnboardingCompleted($student)) {
            return $this->redirectToRoute('app_student_onboarding');
        }
        if (!$this->isCsrfTokenValid('student_parent_link_revoke', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $reference = (string) $request->request->get('reference', '');
        $linkId = $this->query->linkIdForStudentReference($student, $reference);
        if (null === $linkId) {
            throw $this->createNotFoundException();
        }

        try {
            $this->links->revokeLink($student, $linkId);
        } catch (ParentStudentLinkException $exception) {
            return $this->fail($exception);
        }

        $this->addFlash('success', 'Veli bağlantısı kaldırıldı. Yeniden bağlamak için yeni bir kod gerekir.');

        return $this->redirectToRoute('app_student_profile');
    }

    private function student(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function fail(ParentStudentLinkException $exception): Response
    {
        if ('Çok fazla deneme. Lütfen daha sonra tekrar deneyin.' === $exception->getMessage()) {
            throw new TooManyRequestsHttpException(null, $exception->getMessage());
        }
        if ('Bu veli–öğrenci işlemi için yetkiniz yok.' === $exception->getMessage()) {
            throw $this->createAccessDeniedException();
        }
        $this->addFlash('error', $exception->getMessage());

        return $this->redirectToRoute('app_student_profile');
    }
}

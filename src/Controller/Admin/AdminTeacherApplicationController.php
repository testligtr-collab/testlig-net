<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TeacherApplication;
use App\Entity\User;
use App\Exception\OnboardingApplicationException;
use App\Repository\TeacherApplicationRepository;
use App\Security\AdminPermission;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\Admin\AdminNavBuilder;
use App\Service\TeacherApplicationManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * SuperAdmin review queue. Approval does not grant ROLE_TEACHER.
 */
final class AdminTeacherApplicationController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly TeacherApplicationRepository $applications,
        private readonly TeacherApplicationManager $applicationManager,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/ogretmen-basvurulari', name: 'app_admin_teacher_applications', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function list(): Response
    {
        $this->requireSuperAdmin();

        return $this->renderAdmin('admin/teacher_applications/list.html.twig', [
            'applications' => $this->applications->findPendingOrdered(),
        ]);
    }

    #[Route('/yonetim/ogretmen-basvurulari/{id}', name: 'app_admin_teacher_application', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function detail(Uuid $id): Response
    {
        $this->requireSuperAdmin();

        return $this->renderAdmin('admin/teacher_applications/detail.html.twig', [
            'application' => $this->application($id),
        ]);
    }

    #[Route('/yonetim/ogretmen-basvurulari/{id}/onayla', name: 'app_admin_teacher_application_approve', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function approve(Request $request, Uuid $id): Response
    {
        $actor = $this->requireSuperAdmin();
        if (!$this->isCsrfTokenValid('teacher_application_approve', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $this->application($id);

        try {
            $this->applicationManager->markApproved($actor, $id, null);
        } catch (OnboardingApplicationException $exception) {
            return $this->decisionFailure($id, $exception);
        }
        $this->addFlash('success', 'Başvuru onaylandı. Öğretmen rolü verilmedi.');

        return $this->redirectToRoute('app_admin_teacher_application', ['id' => $id]);
    }

    #[Route('/yonetim/ogretmen-basvurulari/{id}/reddet', name: 'app_admin_teacher_application_reject', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function reject(Request $request, Uuid $id): Response
    {
        $actor = $this->requireSuperAdmin();
        if (!$this->isCsrfTokenValid('teacher_application_reject', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $this->application($id);

        try {
            $this->applicationManager->markRejected($actor, $id, $request->request->getString('reason_code'));
        } catch (OnboardingApplicationException $exception) {
            return $this->decisionFailure($id, $exception);
        }
        $this->addFlash('success', 'Başvuru reddedildi.');

        return $this->redirectToRoute('app_admin_teacher_application', ['id' => $id]);
    }

    private function requireSuperAdmin(): User
    {
        $actor = $this->requireActorUser();
        if (!$this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($actor)) {
            throw new AccessDeniedHttpException('Bu işlem için yetkiniz yok.');
        }

        return $actor;
    }

    private function application(Uuid $id): TeacherApplication
    {
        $application = $this->applications->findOneById($id);
        if (null === $application) {
            throw new NotFoundHttpException('Başvuru bulunamadı.');
        }

        return $application;
    }

    private function decisionFailure(Uuid $id, OnboardingApplicationException $exception): Response
    {
        if ('Bu işlem için yetkiniz yok.' === $exception->getMessage()) {
            throw new AccessDeniedHttpException($exception->getMessage(), $exception);
        }
        if ('Başvuru bulunamadı.' === $exception->getMessage()) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }
        $this->addFlash('error', $exception->getMessage());

        return $this->redirectToRoute('app_admin_teacher_application', ['id' => $id]);
    }
}

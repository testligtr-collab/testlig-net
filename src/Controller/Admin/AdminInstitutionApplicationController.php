<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\InstitutionApplication;
use App\Entity\User;
use App\Exception\OnboardingApplicationException;
use App\Repository\InstitutionApplicationRepository;
use App\Security\AdminPermission;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\Admin\AdminNavBuilder;
use App\Service\InstitutionApplicationManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * SuperAdmin review queue. Approval does not create an institution, membership, or role.
 */
final class AdminInstitutionApplicationController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly InstitutionApplicationRepository $applications,
        private readonly InstitutionApplicationManager $applicationManager,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/kurum-basvurulari', name: 'app_admin_institution_applications', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function list(): Response
    {
        $this->requireSuperAdmin();

        return $this->renderAdmin('admin/institution_applications/list.html.twig', [
            'applications' => $this->applications->findPendingOrdered(),
        ]);
    }

    #[Route('/yonetim/kurum-basvurulari/{id}', name: 'app_admin_institution_application', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function detail(Uuid $id): Response
    {
        $this->requireSuperAdmin();

        return $this->renderAdmin('admin/institution_applications/detail.html.twig', [
            'application' => $this->application($id),
        ]);
    }

    #[Route('/yonetim/kurum-basvurulari/{id}/onayla', name: 'app_admin_institution_application_approve', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function approve(Request $request, Uuid $id): Response
    {
        $actor = $this->requireSuperAdmin();
        if (!$this->isCsrfTokenValid('institution_application_approve', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $this->application($id);

        try {
            $this->applicationManager->markApproved($actor, $id, null);
        } catch (OnboardingApplicationException $exception) {
            return $this->decisionFailure($id, $exception);
        }
        $this->addFlash('success', 'Başvuru onaylandı. Kurum, üyelik veya rol verilmedi.');

        return $this->redirectToRoute('app_admin_institution_application', ['id' => $id]);
    }

    #[Route('/yonetim/kurum-basvurulari/{id}/reddet', name: 'app_admin_institution_application_reject', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function reject(Request $request, Uuid $id): Response
    {
        $actor = $this->requireSuperAdmin();
        if (!$this->isCsrfTokenValid('institution_application_reject', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $this->application($id);

        try {
            $this->applicationManager->markRejected($actor, $id, $request->request->getString('reason_code'));
        } catch (OnboardingApplicationException $exception) {
            return $this->decisionFailure($id, $exception);
        }
        $this->addFlash('success', 'Başvuru reddedildi.');

        return $this->redirectToRoute('app_admin_institution_application', ['id' => $id]);
    }

    private function requireSuperAdmin(): User
    {
        $actor = $this->requireActorUser();
        if (!$this->activeVerifiedUserPolicy->isActiveVerifiedSuperAdmin($actor)) {
            throw new AccessDeniedHttpException('Bu işlem için yetkiniz yok.');
        }

        return $actor;
    }

    private function application(Uuid $id): InstitutionApplication
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

        return $this->redirectToRoute('app_admin_institution_application', ['id' => $id]);
    }
}

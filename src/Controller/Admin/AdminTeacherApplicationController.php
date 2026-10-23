<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\AdminTeacherApplicationRow;
use App\Entity\TeacherApplication;
use App\Entity\User;
use App\Enum\UserManagementFailureReason;
use App\Exception\InvalidUserTransitionException;
use App\Exception\OnboardingApplicationException;
use App\Repository\TeacherApplicationRepository;
use App\Security\AdminPermission;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminTeacherApplicationQuery;
use App\Service\TeacherApplicationManager;
use App\Service\TeacherApplicationRoleGrant;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
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
        private readonly TeacherApplicationRoleGrant $roleGrant,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly AdminTeacherApplicationQuery $applicationQuery,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/ogretmen-basvurulari', name: 'app_admin_teacher_applications', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function list(Request $request): Response
    {
        $this->requireSuperAdmin();
        $list = $this->applicationQuery->search($request->query->all());

        return $this->renderAdmin('admin/teacher_applications/list.html.twig', [
            'summary' => $this->applicationQuery->summary(),
            'result' => $list->result,
            'filters' => $list->filters,
        ]);
    }

    #[Route('/yonetim/ogretmen-basvurulari/{id}', name: 'app_admin_teacher_application', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function detail(Request $request, Uuid $id): Response
    {
        $this->requireSuperAdmin();
        $application = $this->application($id);
        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            throw new \LogicException('Oturum flaş bilgisi taşımıyor.');
        }
        $reasonFlashes = $session->getFlashBag()->get('teacher_application_reason');
        $reasonErrors = $session->getFlashBag()->get('teacher_application_reason_error');
        $postedReason = '';
        foreach ($reasonFlashes as $reasonFlash) {
            if (\is_string($reasonFlash)) {
                $postedReason = $reasonFlash;
                break;
            }
        }

        return $this->renderAdmin('admin/teacher_applications/detail.html.twig', [
            'application' => $application,
            'row' => AdminTeacherApplicationRow::fromApplication($application),
            'posted_reason' => $postedReason,
            'reason_invalid' => [] !== $reasonErrors,
            'audit_href' => $this->isGranted(AdminPermission::ADMIN_AUDIT_VIEW)
                ? $this->generateUrl('app_admin_audit')
                : null,
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
            if ('Başvuru verisi geçersiz.' === $exception->getMessage()) {
                $posted = trim($request->request->getString('reason_code'));
                if (mb_strlen($posted) > 64) {
                    $posted = mb_substr($posted, 0, 64);
                }
                $this->addFlash('teacher_application_reason', $posted);
                $this->addFlash('teacher_application_reason_error', '1');
            }

            return $this->decisionFailure($id, $exception);
        }
        $this->addFlash('success', 'Başvuru reddedildi.');

        return $this->redirectToRoute('app_admin_teacher_application', ['id' => $id]);
    }

    #[Route('/yonetim/ogretmen-basvurulari/{id}/ogretmen-rolu', name: 'app_admin_teacher_application_grant_role', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function grantTeacherRole(Request $request, Uuid $id): Response
    {
        $actor = $this->requireSuperAdmin();
        if (!$this->isCsrfTokenValid('teacher_application_grant_role', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $this->application($id);
        if ('1' !== $request->request->getString('confirm')) {
            $this->addFlash('error', 'Öğretmen rolünü vermek için onay gereklidir.');

            return $this->redirectToRoute('app_admin_teacher_application', ['id' => $id]);
        }

        try {
            $granted = $this->roleGrant->grant($actor, $id);
        } catch (OnboardingApplicationException $exception) {
            return $this->decisionFailure($id, $exception);
        } catch (InvalidUserTransitionException $exception) {
            return $this->roleGrantFailure($id, $exception);
        }
        $this->addFlash('success', $granted ? 'Öğretmen rolü verildi.' : 'Öğretmen rolü zaten var.');

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

    private function roleGrantFailure(Uuid $id, InvalidUserTransitionException $exception): Response
    {
        $reason = $exception->getReason();
        if (\in_array($reason, [
            UserManagementFailureReason::ActorNotAuthorized,
            UserManagementFailureReason::ActorNotActive,
            UserManagementFailureReason::ActorNotVerified,
        ], true)) {
            throw new AccessDeniedHttpException('Bu işlem için yetkiniz yok.', $exception);
        }
        if (UserManagementFailureReason::UserNotFound === $reason) {
            throw new NotFoundHttpException('Başvuru bulunamadı.', $exception);
        }
        $message = match ($reason) {
            UserManagementFailureReason::SelfManagementForbidden => 'Kendi hesabınız üzerinde bu işlem yapılamaz.',
            UserManagementFailureReason::ProtectedSuperAdmin => 'Süper yönetici hesabı bu yoldan yönetilemez.',
            UserManagementFailureReason::Conflict => 'Eşzamanlı işlem çakışması; lütfen yenileyin.',
            UserManagementFailureReason::InvalidInput => 'Geçersiz girdi.',
            default => 'İşlem tamamlanamadı.',
        };
        $this->addFlash('error', $message);

        return $this->redirectToRoute('app_admin_teacher_application', ['id' => $id]);
    }
}

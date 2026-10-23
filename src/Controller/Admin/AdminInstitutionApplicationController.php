<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\AdminInstitutionApplicationRow;
use App\Entity\InstitutionApplication;
use App\Entity\User;
use App\Enum\InstitutionFailureReason;
use App\Exception\InstitutionOperationException;
use App\Exception\OnboardingApplicationException;
use App\Presentation\InstitutionApplicationRejectReason;
use App\Repository\InstitutionApplicationRepository;
use App\Security\AdminPermission;
use App\Service\ActiveVerifiedUserPolicy;
use App\Service\Admin\AdminInstitutionApplicationQuery;
use App\Service\Admin\AdminNavBuilder;
use App\Service\InstitutionApplicationInstitutionCreate;
use App\Service\InstitutionApplicationManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
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
        private readonly InstitutionApplicationInstitutionCreate $institutionCreate,
        private readonly ActiveVerifiedUserPolicy $activeVerifiedUserPolicy,
        private readonly AdminInstitutionApplicationQuery $applicationQuery,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/kurum-basvurulari', name: 'app_admin_institution_applications', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function list(Request $request): Response
    {
        $this->requireSuperAdmin();
        $list = $this->applicationQuery->search($request->query->all());

        return $this->renderAdmin('admin/institution_applications/list.html.twig', [
            'summary' => $this->applicationQuery->summary(),
            'result' => $list->result,
            'filters' => $list->filters,
        ]);
    }

    #[Route('/yonetim/kurum-basvurulari/{id}', name: 'app_admin_institution_application', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function detail(Request $request, Uuid $id): Response
    {
        $this->requireSuperAdmin();
        $application = $this->application($id);
        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            throw new \LogicException('Oturum flaş bilgisi taşımıyor.');
        }
        $reasonFlashes = $session->getFlashBag()->get('institution_application_reason');
        $reasonErrors = $session->getFlashBag()->get('institution_application_reason_error');
        $reasonMessages = $session->getFlashBag()->get('institution_application_reason_message');
        $postedReason = '';
        foreach ($reasonFlashes as $reasonFlash) {
            if (\is_string($reasonFlash)) {
                $postedReason = $reasonFlash;
                break;
            }
        }
        $reasonError = '';
        foreach ($reasonMessages as $reasonMessage) {
            if (\is_string($reasonMessage)) {
                $reasonError = $reasonMessage;
                break;
            }
        }
        $created = $application->getCreatedInstitution();
        $institutionHref = null;
        if (null !== $created && $this->isGranted(AdminPermission::ADMIN_INSTITUTIONS_VIEW)) {
            $institutionHref = $this->generateUrl('app_admin_institution_detail', ['id' => $created->getId()]);
        }

        return $this->renderAdmin('admin/institution_applications/detail.html.twig', [
            'application' => $application,
            'row' => AdminInstitutionApplicationRow::fromApplication($application),
            'posted_reason' => $postedReason,
            'reason_invalid' => [] !== $reasonErrors,
            'reason_error' => $reasonError,
            'reject_reasons' => InstitutionApplicationRejectReason::choices(),
            'legacy_reason_label' => InstitutionApplicationRejectReason::LEGACY_LABEL,
            'institution_href' => $institutionHref,
            'audit_href' => $this->isGranted(AdminPermission::ADMIN_AUDIT_VIEW)
                ? $this->generateUrl('app_admin_audit')
                : null,
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
        $posted = trim($request->request->getString('reason_code'));
        if (mb_strlen($posted) > 64) {
            $posted = mb_substr($posted, 0, 64);
        }
        if (!InstitutionApplicationRejectReason::isAllowed($posted)) {
            return $this->rejectChoiceFailure($id, $posted);
        }

        try {
            $this->applicationManager->markRejected($actor, $id, $posted);
        } catch (OnboardingApplicationException $exception) {
            return $this->decisionFailure($id, $exception);
        }
        $this->addFlash('success', 'Başvuru reddedildi.');

        return $this->redirectToRoute('app_admin_institution_application', ['id' => $id]);
    }

    #[Route('/yonetim/kurum-basvurulari/{id}/kurum-olustur', name: 'app_admin_institution_application_create', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function createInstitution(Request $request, Uuid $id): Response
    {
        $actor = $this->requireSuperAdmin();
        if (!$this->isCsrfTokenValid('institution_application_create_institution', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $this->application($id);
        if ('1' !== $request->request->getString('confirm')) {
            $this->addFlash('error', 'Kurum oluşturmak için onay gereklidir.');

            return $this->redirectToRoute('app_admin_institution_application', ['id' => $id]);
        }

        try {
            $created = $this->institutionCreate->create($actor, $id);
        } catch (OnboardingApplicationException $exception) {
            return $this->decisionFailure($id, $exception);
        } catch (InstitutionOperationException $exception) {
            return $this->createFailure($id, $exception);
        }
        $this->addFlash('success', $created ? 'Kurum oluşturuldu.' : 'Bu başvurudan kurum zaten oluşturuldu.');

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

    private function rejectChoiceFailure(Uuid $id, string $posted): Response
    {
        $message = InstitutionApplicationRejectReason::invalidMessage($posted);
        $this->addFlash('institution_application_reason', $posted);
        $this->addFlash('institution_application_reason_error', '1');
        $this->addFlash('institution_application_reason_message', $message);
        $this->addFlash('error', $message);

        return $this->redirectToRoute('app_admin_institution_application', ['id' => $id]);
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

    private function createFailure(Uuid $id, InstitutionOperationException $exception): Response
    {
        if (InstitutionFailureReason::Unauthorized === $exception->getReason()) {
            throw new AccessDeniedHttpException('Bu işlem için yetkiniz yok.', $exception);
        }
        if (InstitutionFailureReason::NotFound === $exception->getReason()) {
            throw new NotFoundHttpException('Başvuru bulunamadı.', $exception);
        }
        $this->addFlash('error', 'Kurum oluşturulamadı. Yazım yapılmadı.');

        return $this->redirectToRoute('app_admin_institution_application', ['id' => $id]);
    }
}

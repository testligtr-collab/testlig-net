<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\CommerceFailureReason;
use App\Exception\CommerceException;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use App\Service\Admin\AdminTeacherDirectoryQuery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Teacher directory. Role and status changes stay on the user detail screen.
 */
final class AdminTeacherDirectoryController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminTeacherDirectoryQuery $teachers,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/ogretmenler', name: 'app_admin_teachers', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function list(Request $request): Response
    {
        $filters = [
            'q' => $request->query->getString('q'),
            'status' => $request->query->getString('status'),
            'role' => $request->query->getString('role'),
            'subject' => $request->query->getString('subject'),
            'page' => $request->query->getInt('page', 1),
            'page_size' => $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE),
        ];

        try {
            $list = $this->teachers->list($this->requireActorId(), $filters);
            $summary = $this->teachers->summary($this->requireActorId());
        } catch (CommerceException $e) {
            if (CommerceFailureReason::InvalidInput === $e->getReason()) {
                throw new NotFoundHttpException('Geçersiz filtre.', $e);
            }
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/teachers/list.html.twig', [
            'summary' => $summary,
            'result' => $list->result,
            'filters' => $list->filters,
            'subjects' => $list->subjects,
            'role_choices' => $list->roleChoices,
            'applications_href' => $this->isGranted(AdminPermission::ADMIN_MEMBERSHIPS_MANAGE)
                ? $this->generateUrl('app_admin_teacher_applications')
                : null,
        ]);
    }

    #[Route('/yonetim/ogretmenler/{id}', name: 'app_admin_teacher', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_USERS_VIEW)]
    public function detail(Uuid $id): Response
    {
        try {
            $teacher = $this->teachers->detail($this->requireActorId(), $id);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/teachers/detail.html.twig', [
            'teacher' => $teacher,
            'user_href' => $this->isGranted(AdminPermission::ADMIN_USERS_VIEW)
                ? $this->generateUrl('app_admin_user_detail', ['id' => $teacher->id])
                : null,
            'audit_href' => $this->isGranted(AdminPermission::ADMIN_AUDIT_VIEW)
                ? $this->generateUrl('app_admin_audit')
                : null,
        ]);
    }
}

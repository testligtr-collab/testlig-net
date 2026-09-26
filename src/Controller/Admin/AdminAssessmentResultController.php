<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Assessment;
use App\Repository\AssessmentRepository;
use App\Security\AdminPermission;
use App\Security\AssessmentPermission;
use App\Service\Admin\AdminLikeEscape;
use App\Service\Admin\AdminNavBuilder;
use App\Service\AssessmentResultReportGate;
use App\Service\AssessmentResultReportQuery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
final class AdminAssessmentResultController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AssessmentRepository $assessments,
        private readonly AssessmentResultReportGate $gate,
        private readonly AssessmentResultReportQuery $report,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/testler/{id}/sonuclar', name: 'app_admin_test_results', methods: ['GET'])]
    public function index(Request $request, string $id): Response
    {
        $assessment = $this->visibleAssessment($id);
        $search = AdminLikeEscape::normalizeSearch($request->query->getString('q'));

        return $this->renderAdmin('admin/tests/results.html.twig', [
            'assessment_id' => $assessment->getId()->toRfc4122(),
            'title' => $assessment->getPublishedRevision()?->getTitle()
                ?? $assessment->getCurrentRevision()?->getTitle()
                ?? '',
            'search' => $search ?? '',
            'summary' => $this->report->summarize($assessment, $search),
            'result' => $this->report->page($assessment, $request->query->getInt('page', 1), $search),
        ]);
    }

    #[Route('/yonetim/testler/{id}/sonuclar/{rank}', name: 'app_admin_test_result', methods: ['GET'], requirements: ['rank' => '[1-9][0-9]*'])]
    public function show(Request $request, string $id, int $rank): Response
    {
        $assessment = $this->visibleAssessment($id);
        $search = AdminLikeEscape::normalizeSearch($request->query->getString('q'));
        $detail = $this->report->detail($assessment, $rank, $search);
        if (null === $detail) {
            throw $this->createNotFoundException();
        }

        return $this->renderAdmin('admin/tests/result.html.twig', [
            'assessment_id' => $assessment->getId()->toRfc4122(),
            'search' => $search ?? '',
            'detail' => $detail,
        ]);
    }

    private function visibleAssessment(string $id): Assessment
    {
        try {
            $assessmentId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        $assessment = $this->assessments->findOneById($assessmentId);
        if (!$assessment instanceof Assessment || !$this->isGranted(AssessmentPermission::VIEW, $assessment)) {
            throw $this->createNotFoundException();
        }
        if (!$this->gate->canRead($this->requireActorUser(), $assessment)) {
            throw $this->createAccessDeniedException();
        }

        return $assessment;
    }
}

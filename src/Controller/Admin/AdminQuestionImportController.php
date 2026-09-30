<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Question\Import\QuestionCsvImportApplier;
use App\Question\Import\QuestionCsvImportException;
use App\Question\Import\QuestionCsvImportPlanner;
use App\Question\Import\QuestionCsvImportPlanStore;
use App\Question\Import\QuestionCsvParser;
use App\Question\Import\QuestionCsvTemplate;
use App\Security\AdminAuthorization;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class AdminQuestionImportController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly QuestionCsvParser $parser,
        private readonly QuestionCsvImportPlanner $planner,
        private readonly QuestionCsvImportPlanStore $plans,
        private readonly QuestionCsvImportApplier $applier,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/sorular/ice-aktar', name: 'app_admin_question_import', methods: ['GET', 'POST'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function import(Request $request): Response
    {
        $actor = $this->requireActorUser();
        $this->assertAuthor($actor);
        if ($request->isMethod('GET')) {
            return $this->renderAdmin('admin/questions/import.html.twig', [
                'file_error' => null,
            ]);
        }

        if (!$this->isCsrfTokenValid('question_csv_import', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Oturum doğrulaması başarısız.');
        }

        $upload = $request->files->get('csv');
        if (!$upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$upload->isValid()) {
            return $this->renderAdmin('admin/questions/import.html.twig', [
                'file_error' => 'Bir CSV dosyası seçin.',
            ]);
        }
        if ('csv' !== strtolower($upload->getClientOriginalExtension())) {
            return $this->renderAdmin('admin/questions/import.html.twig', [
                'file_error' => 'Yalnız .csv dosyası kabul edilir.',
            ]);
        }
        $bytes = file_get_contents($upload->getPathname());
        if (!\is_string($bytes)) {
            return $this->renderAdmin('admin/questions/import.html.twig', [
                'file_error' => 'Dosya okunamadı.',
            ]);
        }

        try {
            $records = $this->parser->parse($bytes);
            $plan = $this->planner->plan($records);
        } catch (QuestionCsvImportException $exception) {
            return $this->renderAdmin('admin/questions/import.html.twig', [
                'file_error' => $exception->getMessage(),
            ]);
        }

        $digestSource = str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
        $planId = $this->plans->save(
            $actor->getId()->toRfc4122(),
            hash('sha256', $digestSource),
            $plan->decisionDigest(),
            $records,
        );

        return $this->redirectToRoute('app_admin_question_import_preview', ['planId' => $planId]);
    }

    #[Route('/yonetim/sorular/ice-aktar/sablon', name: 'app_admin_question_import_template', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function template(): Response
    {
        $this->assertAuthor($this->requireActorUser());
        $response = new Response(QuestionCsvTemplate::BODY);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'sablon.csv');
        $response->headers->set('Content-Disposition', $disposition);
        $this->applyNoStore($response);

        return $response;
    }

    #[Route('/yonetim/sorular/ice-aktar/{planId}', name: 'app_admin_question_import_preview', requirements: ['planId' => '[a-f0-9]{64}'], methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function preview(string $planId): Response
    {
        $actor = $this->requireActorUser();
        $this->assertAuthor($actor);

        try {
            $stored = $this->plans->load($planId, $actor->getId()->toRfc4122());
            $plan = $this->planner->plan($stored['records']);
        } catch (QuestionCsvImportException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_admin_question_import');
        }

        $stale = !hash_equals($stored['decision_digest'], $plan->decisionDigest());

        return $this->renderAdmin('admin/questions/import_preview.html.twig', [
            'plan_id' => $planId,
            'rows' => $plan->rows,
            'created_count' => $plan->createdCount,
            'skipped_count' => $plan->skippedCount,
            'error_count' => $plan->errorCount,
            'conflict_count' => $plan->conflictCount,
            'can_apply' => $plan->canApply() && !$stale,
            'stale' => $stale,
        ]);
    }

    #[Route('/yonetim/sorular/ice-aktar/{planId}/uygula', name: 'app_admin_question_import_apply', requirements: ['planId' => '[a-f0-9]{64}'], methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_QUESTION_VIEW)]
    public function apply(Request $request, string $planId): Response
    {
        $actor = $this->requireActorUser();
        $this->assertAuthor($actor);
        if (!$this->isCsrfTokenValid('question_csv_import_apply', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Oturum doğrulaması başarısız.');
        }

        try {
            $result = $this->applier->apply($actor, $planId, '1' === $request->request->getString('confirm'));
        } catch (QuestionCsvImportException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_admin_question_import_preview', ['planId' => $planId]);
        }

        $this->addFlash('success', \sprintf('%d soru taslak olarak oluşturuldu. %d satır atlandı.', $result['created'], $result['skipped']));

        return $this->redirectToRoute('app_admin_questions');
    }

    private function assertAuthor(\App\Entity\User $actor): void
    {
        if (!$this->adminAuthorization->canAuthorQuestions($actor)) {
            throw $this->createAccessDeniedException();
        }
    }
}

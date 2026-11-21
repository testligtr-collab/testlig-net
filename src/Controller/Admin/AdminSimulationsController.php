<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\AdminSimulationDraftCreateRequest;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\Subject;
use App\Enum\GradeLevel;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\SubjectStatus;
use App\Exception\LearningContentException;
use App\Form\AdminSimulationDraftCreateFormType;
use App\LearningContent\Content\LearningContentDocument;
use App\Presentation\AdminSimulationsDraftLabels;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\LearningContentRepository;
use App\Repository\SubjectRepository;
use App\Security\AdminPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use App\Service\LearningContentManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * SuperAdmin-only platform simulation draft list and create (no motor/publish).
 */
final class AdminSimulationsController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly LearningContentRepository $contents,
        private readonly LearningContentManager $contentManager,
        private readonly SubjectRepository $subjects,
        private readonly CurriculumLearningOutcomeRepository $outcomes,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/bolum/simulasyonlar', name: 'app_admin_simulations', methods: ['GET'], priority: 10)]
    #[IsGranted(AdminPermission::ADMIN_SECTION_ENTRY)]
    public function index(Request $request): Response
    {
        $total = $this->contents->countPlatformSimulationDraftsForAdmin();
        $pageSize = AdminPagination::DEFAULT_PAGE_SIZE;
        $lastPage = max(1, intdiv(max(0, $total - 1), $pageSize) + 1);
        $page = min($lastPage, AdminPagination::normalizePage($request->query->getInt('page', 1)));
        $rows = [];
        foreach ($this->contents->listPlatformSimulationDraftsForAdmin(AdminPagination::offset($page, $pageSize), $pageSize) as $row) {
            $rows[] = [
                'content_id' => $row['content_id'],
                'content_title' => $row['content_title'],
                'subject_name' => $row['subject_name'],
                'updated_at_label' => AdminSimulationsDraftLabels::updatedAt($row['updated_at']),
            ];
        }

        return $this->renderAdmin('admin/simulations/index.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
        ]);
    }

    #[Route('/yonetim/bolum/simulasyonlar/yeni', name: 'app_admin_simulations_new', methods: ['GET', 'POST'], priority: 10)]
    #[IsGranted(AdminPermission::ADMIN_SECTION_ENTRY)]
    public function create(Request $request): Response
    {
        $dto = new AdminSimulationDraftCreateRequest();
        $form = $this->createForm(AdminSimulationDraftCreateFormType::class, $dto, [
            'subject_choices' => $this->subjectChoiceMap(),
            'outcome_choices' => $this->outcomeChoiceMap(),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            if ($form->isValid()) {
                try {
                    $gradeLevel = $dto->gradeLevel;
                    if (!$gradeLevel instanceof GradeLevel) {
                        $this->addFlash('error', 'Sınıf seviyesi zorunludur.');
                    } else {
                        $subject = $this->requireActiveSubject((string) $dto->subjectId);
                        $outcome = $this->requireOutcomeForSubject((string) $dto->learningOutcomeId, $subject);
                        $content = $this->contentManager->createDraft(
                            $this->requireActorUser(),
                            LearningContentScope::Platform,
                            null,
                            $subject,
                            $gradeLevel,
                            LearningContentType::Simulation,
                            (string) $dto->code,
                            (string) $dto->title,
                            $dto->summary,
                            LearningContentDocument::paragraph('Simülasyon taslağı.'),
                            [['learningOutcome' => $outcome, 'isPrimary' => true]],
                            'admin_simulation_draft_create',
                        );
                        $this->addFlash('success', 'Simülasyon taslağı oluşturuldu.');

                        return $this->redirectToRoute('app_admin_learning_content', [
                            'id' => $content->getId()->toRfc4122(),
                        ]);
                    }
                } catch (LearningContentException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        return $this->renderAdmin('admin/simulations/new.html.twig', [
            'form' => $form,
        ], $form->isSubmitted() && !$form->isValid() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    /**
     * @return array<string, string>
     */
    private function subjectChoiceMap(): array
    {
        $choices = [];
        foreach ($this->subjects->findActiveOrdered() as $subject) {
            $label = \sprintf('%s (%s)', $subject->getName(), $subject->getCode());
            $choices[$label] = $subject->getId()->toRfc4122();
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function outcomeChoiceMap(): array
    {
        $choices = [];
        foreach ($this->subjects->findActiveOrdered() as $subject) {
            foreach ($this->outcomes->findActiveOrderedForSubject($subject) as $outcome) {
                $label = \sprintf(
                    '[%s] %s — %s',
                    $subject->getCode(),
                    $outcome->getCode(),
                    mb_substr($outcome->getDescription(), 0, 80),
                );
                $choices[$label] = $outcome->getId()->toRfc4122();
            }
        }

        return $choices;
    }

    private function requireActiveSubject(string $id): Subject
    {
        if (!Uuid::isValid($id)) {
            throw LearningContentException::invalidInput('Konu alanı geçersiz.');
        }
        $subject = $this->subjects->find(Uuid::fromString($id));
        if (!$subject instanceof Subject || SubjectStatus::Active !== $subject->getStatus()) {
            throw LearningContentException::invalidInput('Konu alanı aktif değil.');
        }

        return $subject;
    }

    private function requireOutcomeForSubject(string $id, Subject $subject): CurriculumLearningOutcome
    {
        if (!Uuid::isValid($id)) {
            throw LearningContentException::invalidInput('Kazanım geçersiz.');
        }
        $outcome = $this->outcomes->find(Uuid::fromString($id));
        if (!$outcome instanceof CurriculumLearningOutcome) {
            throw LearningContentException::invalidInput('Kazanım bulunamadı.');
        }
        if (!$outcome->getCurriculumProgram()->getSubject()->getId()->equals($subject->getId())) {
            throw LearningContentException::invalidInput('Kazanım seçilen konu alanına ait değil.');
        }

        return $outcome;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Assessment\AssessmentScore;
use App\Entity\Assessment;
use App\Entity\AssessmentItem;
use App\Entity\AssessmentRevision;
use App\Entity\AssessmentSection;
use App\Entity\CatalogTopic;
use App\Entity\CatalogTopicAssessment;
use App\Entity\Question;
use App\Entity\QuestionRevision;
use App\Entity\Subject;
use App\Entity\User;
use App\Enum\AssessmentFailureReason;
use App\Enum\AssessmentScope;
use App\Enum\AssessmentStatus;
use App\Enum\AssessmentType;
use App\Enum\GradeLevel;
use App\Enum\NavigationMode;
use App\Enum\OptionOrderMode;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionOrderMode;
use App\Enum\QuestionScope;
use App\Enum\QuestionStatus;
use App\Enum\ResourceAccessClass;
use App\Enum\ResultReleasePolicy;
use App\Enum\SubjectStatus;
use App\Exception\AccessEntitlementException;
use App\Exception\AssessmentException;
use App\Exception\CatalogException;
use App\Exception\LearningContentException;
use App\Presentation\ContentWorkflowReason;
use App\Presentation\PlacementPosition;
use App\Presentation\ResultPresentation;
use App\Presentation\TestWorkflowProgress;
use App\Repository\AssessmentAccessPolicyRepository;
use App\Repository\AssessmentItemRepository;
use App\Repository\AssessmentRepository;
use App\Repository\AssessmentRevisionRepository;
use App\Repository\AssessmentSectionRepository;
use App\Repository\CatalogTopicAssessmentRepository;
use App\Repository\CatalogTopicRepository;
use App\Repository\QuestionRepository;
use App\Repository\QuestionRevisionOptionRepository;
use App\Repository\QuestionRevisionRepository;
use App\Repository\SubjectRepository;
use App\Security\AccessPackageAuthorization;
use App\Security\AdminAuthorization;
use App\Security\AdminPermission;
use App\Security\AssessmentPermission;
use App\Security\CatalogTopicAssessmentPermission;
use App\Service\AccessPackageManager;
use App\Service\Admin\AdminNavBuilder;
use App\Service\AssessmentManager;
use App\Service\AssessmentResultReportGate;
use App\Service\CatalogTopicAssessmentManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminTestController extends AdminBaseController
{
    private const PAGE_SIZE = 10;

    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminAuthorization $adminAuthorization,
        private readonly AccessPackageAuthorization $accessPackageAuthorization,
        private readonly AssessmentRepository $assessments,
        private readonly AssessmentRevisionRepository $revisions,
        private readonly AssessmentSectionRepository $sections,
        private readonly AssessmentItemRepository $items,
        private readonly AssessmentAccessPolicyRepository $accessPolicies,
        private readonly QuestionRepository $questions,
        private readonly QuestionRevisionRepository $questionRevisions,
        private readonly QuestionRevisionOptionRepository $options,
        private readonly SubjectRepository $subjects,
        private readonly AssessmentManager $assessmentManager,
        private readonly AccessPackageManager $accessPackages,
        private readonly ContentWorkflowReason $workflowReason,
        private readonly TestWorkflowProgress $workflowProgress,
        private readonly AssessmentResultReportGate $resultReportGate,
        private readonly ResultPresentation $presentation,
        private readonly CatalogTopicAssessmentManager $assessmentPlacements,
        private readonly CatalogTopicAssessmentRepository $assessmentPlacementRepo,
        private readonly CatalogTopicRepository $catalogTopics,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/testler', name: 'app_admin_tests', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function index(Request $request): Response
    {
        $actor = $this->requireActorUser();
        $seeAll = $this->canSeeAll($actor);
        $subjects = $this->subjects->findActiveOrdered();
        $filters = $this->listFilters($request, $subjects);
        $loaded = $this->assessments->findPlatformVisibleFiltered(
            $actor,
            $seeAll,
            $filters['status'],
            $filters['grade'],
            $filters['subject'],
            $filters['text'],
            $filters['blocked'],
        );
        $rows = [];
        foreach ($loaded as $loadedRow) {
            $assessment = $loadedRow['assessment'];
            $revision = $assessment->getCurrentRevision();
            $rows[] = [
                'id' => $assessment->getId()->toRfc4122(),
                'title' => $revision?->getTitle() ?? 'Adsız test',
                'code' => $assessment->getCode(),
                'subject' => $assessment->getSubject()?->getName() ?? '—',
                'grade' => $assessment->getGradeLevel()->value.'. sınıf',
                'status' => $assessment->getStatus()->value,
                'questions' => $loadedRow['questions'],
            ];
        }

        return $this->renderAdmin('admin/tests/list.html.twig', [
            'rows' => $rows,
            'counts' => $this->assessments->countPlatformVisibleByStatus($actor, $seeAll),
            'matched' => $this->assessments->countPlatformVisibleFiltered(
                $actor,
                $seeAll,
                $filters['status'],
                $filters['grade'],
                $filters['subject'],
                $filters['text'],
                $filters['blocked'],
            ),
            'subjects' => $subjects,
            'filters' => [
                'q' => $filters['text'],
                'status' => $filters['status'] instanceof AssessmentStatus ? $filters['status']->value : '',
                'subject_id' => $filters['subject'] instanceof Uuid ? $filters['subject']->toRfc4122() : '',
                'grade' => $filters['grade'] instanceof GradeLevel ? (string) $filters['grade']->value : '',
            ],
            'can_author' => $this->adminAuthorization->canAuthorTests($actor),
        ]);
    }

    #[Route('/yonetim/testler/yeni', name: 'app_admin_test_new', methods: ['GET', 'POST'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function new(Request $request): Response
    {
        $actor = $this->requireActorUser();
        if (!$this->adminAuthorization->canAuthorTests($actor)) {
            throw $this->createAccessDeniedException();
        }
        if ($request->isMethod('POST')) {
            $this->assertEditorCsrf($request);

            return $this->createDraft($request, $actor);
        }

        return $this->renderForm($request, null);
    }

    #[Route('/yonetim/testler/{id}', name: 'app_admin_test_show', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function show(string $id): Response
    {
        $assessment = $this->visibleAssessment($id);
        $revision = $this->currentRevision($assessment);
        $rows = $this->itemRows($revision);
        $actor = $this->requireActorUser();
        $isAuthor = $revision->getCreatedBy()->getId()->equals($actor->getId());
        $policy = $this->accessPolicies->findForAssessment($assessment->getId());
        $canSetPolicy = $this->canManageAssessmentAccessPolicy($actor, $assessment);
        $placements = $this->assessmentPlacementRepo->findOrderedByAssessment($assessment);
        $bindableTopics = $this->bindableTopicsForAssessment($assessment);
        $suggestedPositions = [];
        foreach ($bindableTopics as $topic) {
            $suggestedPositions[$topic->getId()->toRfc4122()] = PlacementPosition::next(
                $this->assessmentPlacementRepo->highestPositionForTopic($topic->getId()),
            );
        }
        $canCreatePlacement = $this->canCreateAssessmentPlacement($assessment);

        return $this->renderAdmin('admin/tests/detail.html.twig', [
            'assessment' => $assessment,
            'revision' => $revision,
            'rows' => $this->presentRows($rows),
            'question_count' => \count($rows),
            'total_points' => $this->presentation->points($this->totalPoints($rows)) ?? '0',
            'duration_label' => $this->durationLabel($revision),
            'status_label' => $this->statusLabels()[$assessment->getStatus()->value] ?? $assessment->getStatus()->value,
            'type_label' => $this->typeLabels()[$assessment->getType()->value] ?? $assessment->getType()->value,
            'subject_name' => $this->presentation->subjectName($assessment->getSubject()),
            'grade_label' => $assessment->getGradeLevel()->value.'. sınıf',
            'can_edit' => $this->canEdit($assessment),
            'can_view_results' => $this->resultReportGate->canRead($actor, $assessment),
            'access_policy' => $policy,
            'access_policy_label' => $this->accessPolicyLabel($policy?->getAccessClass()),
            'can_set_access_policy' => $canSetPolicy,
            'placements' => $placements,
            'bindable_topics' => $bindableTopics,
            'suggested_positions' => $suggestedPositions,
            'can_create_placement' => $canCreatePlacement,
            'reason_placement_create' => ContentWorkflowReason::PLACEMENT_CREATE,
            'reason_placement_publish' => ContentWorkflowReason::PLACEMENT_PUBLISH,
            'reason_placement_archive' => ContentWorkflowReason::PLACEMENT_ARCHIVE,
            'progress' => $this->workflowProgress->summarize([
                'status' => $assessment->getStatus()->value,
                'can_edit' => $this->canEdit($assessment),
                'can_submit' => AssessmentStatus::Draft === $assessment->getStatus() && $this->isGranted(AssessmentPermission::SUBMIT, $assessment),
                'can_return' => AssessmentStatus::InReview === $assessment->getStatus() && $this->isGranted(AssessmentPermission::REVIEW, $assessment),
                'can_publish' => AssessmentStatus::InReview === $assessment->getStatus()
                    && !$isAuthor
                    && $this->isGranted(AssessmentPermission::PUBLISH, $assessment),
                'can_archive' => AssessmentStatus::Published === $assessment->getStatus() && $this->isGranted(AssessmentPermission::ARCHIVE, $assessment),
                'is_revision_author' => $isAuthor,
            ]),
        ]);
    }

    #[Route('/yonetim/testler/{id}/erisim', name: 'app_admin_test_policy', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function setAccessPolicy(Request $request, string $id): Response
    {
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('assessment_access_policy_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }

        $assessment = $this->visibleAssessment($id);
        $actor = $this->requireActorUser();
        if (!$this->canManageAssessmentAccessPolicy($actor, $assessment)) {
            throw $this->createAccessDeniedException();
        }

        $accessClassRaw = (string) $request->request->get('access_class', '');
        $accessClass = ResourceAccessClass::tryFrom($accessClassRaw);
        if (!$accessClass instanceof ResourceAccessClass) {
            $this->addFlash('error', 'Geçersiz erişim politikası.');

            return $this->redirectToRoute('app_admin_test_show', ['id' => $id]);
        }
        if (ResourceAccessClass::Free === $accessClass && '1' !== (string) $request->request->get('confirm_free')) {
            $this->addFlash('error', 'Ücretsiz erişim için onay kutusu zorunludur.');

            return $this->redirectToRoute('app_admin_test_show', ['id' => $id]);
        }

        try {
            $this->accessPackages->setAssessmentAccessPolicy(
                $assessment,
                $actor,
                $accessClass,
                ResourceAccessClass::Free === $accessClass ? 'admin_set_free' : 'admin_set_entitlement',
            );
            $this->addFlash('success', 'Erişim politikası güncellendi.');
        } catch (AccessEntitlementException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_test_show', ['id' => $id]);
    }

    #[Route('/yonetim/testler/{id}/yerlesim', name: 'app_admin_test_placement_create', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function createPlacementFromTest(Request $request, string $id): Response
    {
        $assessment = $this->visibleAssessment($id);
        $this->denyAccessUnlessGranted(CatalogTopicAssessmentPermission::CREATE);
        $this->assertPlacementCsrf($request, $id, 'test_placement_');

        if (!$this->canCreateAssessmentPlacement($assessment)) {
            throw $this->createAccessDeniedException();
        }

        $topicIdRaw = (string) $request->request->get('catalog_topic_id', '');
        $displayTitle = trim((string) $request->request->get('display_title', ''));
        $slug = trim((string) $request->request->get('slug', ''));
        $summaryRaw = trim((string) $request->request->get('summary', ''));

        try {
            $topicUuid = Uuid::fromString($topicIdRaw);
            $topic = $this->catalogTopics->findOneById($topicUuid);
            if (!$topic instanceof CatalogTopic || !$this->isBindableTopicForAssessment($topic, $assessment)) {
                $this->addFlash('error', 'Geçersiz katalog konusu.');

                return $this->redirectToRoute('app_admin_test_show', ['id' => $id]);
            }
            if ($this->assessmentPlacementRepo->existsAssessmentForTopic($topic, $assessment)) {
                $this->addFlash('success', 'Yerleşim zaten mevcut.');

                return $this->redirectToRoute('app_admin_test_show', ['id' => $id]);
            }
            $revision = $this->currentRevision($assessment);
            if ('' === $displayTitle) {
                $displayTitle = $revision->getTitle();
            }
            $positionRaw = trim((string) $request->request->get('position', ''));
            $position = '' === $positionRaw
                ? PlacementPosition::next($this->assessmentPlacementRepo->highestPositionForTopic($topicUuid))
                : (int) $positionRaw;
            $reason = $this->workflowReason->resolve(
                ContentWorkflowReason::PLACEMENT_CREATE,
                $request->request->get('note'),
                $request->request->get('operator_note'),
            );
            $this->assessmentPlacements->create(
                $this->requireActorUser(),
                $topicUuid,
                $assessment->getId(),
                $displayTitle,
                '' === $summaryRaw ? null : $summaryRaw,
                $position,
                $reason['code'],
                '' === $slug ? null : $slug,
                $reason['operator_note'],
            );
            $this->addFlash('success', 'Yerleşim taslağı oluşturuldu.');
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'Geçersiz katalog konusu.');
        } catch (LearningContentException) {
            $this->addFlash('error', 'İşlem notu geçersiz.');
        } catch (CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_test_show', ['id' => $id]);
    }

    #[Route('/yonetim/testler/yerlesim/{placementId}/yayimla', name: 'app_admin_test_placement_publish', methods: ['POST'], requirements: ['placementId' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function publishPlacementFromTest(Request $request, string $placementId): Response
    {
        $placement = $this->requireAssessmentPlacement($placementId);
        $assessmentId = $placement->getAssessment()->getId()->toRfc4122();
        $this->visibleAssessment($assessmentId);
        $this->denyAccessUnlessGranted(CatalogTopicAssessmentPermission::PUBLISH, $placement);
        $this->assertPlacementCsrf($request, $placementId, 'test_placement_publish_');
        if ('1' !== (string) $request->request->get('confirm_publish')) {
            $this->addFlash('error', 'Yayımlama için onay kutusu zorunludur.');

            return $this->redirectToRoute('app_admin_test_show', ['id' => $assessmentId]);
        }
        if ($placement->isPublished()) {
            $this->addFlash('success', 'Yerleşim zaten yayımlanmış.');

            return $this->redirectToRoute('app_admin_test_show', ['id' => $assessmentId]);
        }

        try {
            $reason = $this->workflowReason->resolve(
                ContentWorkflowReason::PLACEMENT_PUBLISH,
                $request->request->get('note'),
                $request->request->get('operator_note'),
            );
            $this->assessmentPlacements->publish(
                $this->requireActorUser(),
                $placement->getId(),
                $reason['code'],
                $reason['operator_note'],
            );
            $this->addFlash('success', 'Yerleşim yayımlandı.');
        } catch (LearningContentException) {
            $this->addFlash('error', 'İşlem notu geçersiz.');
        } catch (CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_test_show', ['id' => $assessmentId]);
    }

    #[Route('/yonetim/testler/yerlesim/{placementId}/arsivle', name: 'app_admin_test_placement_archive', methods: ['POST'], requirements: ['placementId' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function archivePlacementFromTest(Request $request, string $placementId): Response
    {
        $placement = $this->requireAssessmentPlacement($placementId);
        $assessmentId = $placement->getAssessment()->getId()->toRfc4122();
        $this->visibleAssessment($assessmentId);
        $this->denyAccessUnlessGranted(CatalogTopicAssessmentPermission::ARCHIVE, $placement);
        $this->assertPlacementCsrf($request, $placementId, 'test_placement_archive_');
        if ($placement->isArchived()) {
            $this->addFlash('success', 'Yerleşim zaten arşivlenmiş.');

            return $this->redirectToRoute('app_admin_test_show', ['id' => $assessmentId]);
        }

        try {
            $reason = $this->workflowReason->resolve(
                ContentWorkflowReason::PLACEMENT_ARCHIVE,
                $request->request->get('note'),
                $request->request->get('operator_note'),
            );
            $this->assessmentPlacements->archive(
                $this->requireActorUser(),
                $placement->getId(),
                $reason['code'],
                $reason['operator_note'],
            );
            $this->addFlash('success', 'Yerleşim arşivlendi (geri açılamaz).');
        } catch (LearningContentException) {
            $this->addFlash('error', 'İşlem notu geçersiz.');
        } catch (CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_test_show', ['id' => $assessmentId]);
    }

    #[Route('/yonetim/testler/{id}/duzenle', name: 'app_admin_test_edit', methods: ['GET', 'POST'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function edit(Request $request, string $id): Response
    {
        $assessment = $this->visibleAssessment($id);
        if (!$this->canEdit($assessment)) {
            throw $this->createAccessDeniedException();
        }
        if ($request->isMethod('POST')) {
            $this->assertEditorCsrf($request);

            return $this->saveRevision($request, $assessment);
        }

        return $this->renderForm($request, $assessment);
    }

    #[Route('/yonetim/testler/{id}/gorunum', name: 'app_admin_test_preview', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function preview(string $id): Response
    {
        $assessment = $this->visibleAssessment($id);
        $revision = $this->currentRevision($assessment);

        return $this->renderAdmin('admin/tests/preview.html.twig', [
            'title' => $revision->getTitle(),
            'instructions' => $revision->getInstructions(),
            'rows' => $this->previewRows($revision),
        ]);
    }

    #[Route('/yonetim/testler/{id}/incelemeye-gonder', name: 'app_admin_test_submit', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function submit(Request $request, string $id): Response
    {
        return $this->lifecycle($request, $id, 'submit');
    }

    #[Route('/yonetim/testler/{id}/taslaga-dondur', name: 'app_admin_test_return', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function returnToDraft(Request $request, string $id): Response
    {
        return $this->lifecycle($request, $id, 'return');
    }

    #[Route('/yonetim/testler/{id}/yayinla', name: 'app_admin_test_publish', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function publish(Request $request, string $id): Response
    {
        return $this->lifecycle($request, $id, 'publish');
    }

    #[Route('/yonetim/testler/{id}/arsivle', name: 'app_admin_test_archive', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_TEST_VIEW)]
    public function archive(Request $request, string $id): Response
    {
        return $this->lifecycle($request, $id, 'archive');
    }

    private function createDraft(Request $request, User $actor): Response
    {
        try {
            $input = $this->readInput($request, null);
            $assessment = $this->assessmentManager->createDraftAssessment(
                $actor,
                AssessmentScope::Platform,
                null,
                AssessmentType::Quiz,
                $input['grade'],
                $input['title'],
                null,
                $input['instructions'],
                $input['duration'],
                NavigationMode::Free,
                QuestionOrderMode::Fixed,
                OptionOrderMode::Fixed,
                ResultReleasePolicy::Immediate,
                null,
                [$this->section($input['items'])],
                'draft_saved',
                $input['subject'],
            );
        } catch (AssessmentException $exception) {
            return $this->rejectEditor($request, $exception, null);
        }

        $this->addFlash('success', 'Taslak kaydedildi.');

        return $this->redirectToRoute('app_admin_test_show', ['id' => $assessment->getId()->toRfc4122()]);
    }

    private function saveRevision(Request $request, Assessment $assessment): Response
    {
        $current = $assessment->getCurrentRevisionNumber();
        if ($request->request->getInt('expected_revision') !== $current) {
            $this->addFlash('error', 'Bu test başka bir işlemle güncellendi. Sayfayı yenileyip yeniden deneyin.');

            return $this->redirectToRoute('app_admin_test_edit', ['id' => $assessment->getId()->toRfc4122()]);
        }

        try {
            $input = $this->readInput($request, $assessment);
            $this->assessmentManager->createRevision(
                $assessment,
                $this->requireActorUser(),
                $input['title'],
                null,
                $input['instructions'],
                $input['duration'],
                NavigationMode::Free,
                QuestionOrderMode::Fixed,
                OptionOrderMode::Fixed,
                ResultReleasePolicy::Immediate,
                null,
                [$this->section($input['items'])],
                'draft_saved',
                $assessment->getSubject(),
            );
        } catch (AssessmentException $exception) {
            return $this->rejectEditor($request, $exception, $assessment);
        }

        $this->addFlash('success', 'Taslak kaydedildi.');

        return $this->redirectToRoute('app_admin_test_show', ['id' => $assessment->getId()->toRfc4122()]);
    }

    private function lifecycle(Request $request, string $id, string $action): Response
    {
        $this->assertEditorCsrf($request);
        $assessment = $this->visibleAssessment($id);
        $route = ['id' => $assessment->getId()->toRfc4122()];
        if ($request->request->getInt('expected_revision') !== $assessment->getCurrentRevisionNumber()) {
            $this->addFlash('error', 'Bu test başka bir işlemle güncellendi. Sayfayı yenileyip yeniden deneyin.');

            return $this->redirectToRoute('app_admin_test_show', $route);
        }
        if ('publish' === $action) {
            $revision = $assessment->getCurrentRevision();
            $actor = $this->requireActorUser();
            if ($revision instanceof AssessmentRevision && $revision->getCreatedBy()->getId()->equals($actor->getId())) {
                $this->addFlash('error', 'Kendi hazırladığınız testi yayımlayamazsınız.');

                return $this->redirectToRoute('app_admin_test_show', $route);
            }
        }

        try {
            $resolved = $this->workflowReason->resolve($this->defaultReason($action), null, $request->request->get('operator_note'));
            match ($action) {
                'submit' => $this->assessmentManager->submitForReview($assessment, $this->requireActorUser(), $resolved['code'], $resolved['operator_note']),
                'return' => $this->assessmentManager->returnToDraft($assessment, $this->requireActorUser(), $resolved['code'], $resolved['operator_note']),
                'publish' => $this->assessmentManager->publish($assessment, $this->requireActorUser(), $resolved['code'], $resolved['operator_note']),
                'archive' => $this->assessmentManager->archive($assessment, $this->requireActorUser(), $resolved['code'], $resolved['operator_note']),
                default => throw AssessmentException::invalidTransition(),
            };
        } catch (LearningContentException) {
            $this->addFlash('error', 'İşlem notu geçersiz.');

            return $this->redirectToRoute('app_admin_test_show', $route);
        } catch (AssessmentException $exception) {
            return $this->fail($exception, 'app_admin_test_show', $route);
        }

        $this->addFlash('success', $this->successMessage($action));

        return $this->redirectToRoute('app_admin_test_show', $route);
    }

    /**
     * @param array{
     *     title: string,
     *     instructions: string,
     *     duration: string,
     *     grade: ?GradeLevel,
     *     subject: ?Subject,
     *     selected: list<array{position: string, code: string, points: string, stem: string, question_id: string}>,
     *     filters: array{q: string, code: string, outcome: string, difficulty: string},
     *     page: int
     * }|null $replay
     */
    private function renderForm(Request $request, ?Assessment $assessment, ?array $replay = null): Response
    {
        $revision = $assessment instanceof Assessment ? $this->currentRevision($assessment) : null;
        if ($assessment instanceof Assessment) {
            $subject = $assessment->getSubject();
            $grade = $assessment->getGradeLevel();
        } elseif (\is_array($replay)) {
            $subject = $replay['subject'];
            $grade = $replay['grade'];
        } else {
            $subject = $this->activeSubject($this->subjectFromQuery($request));
            $grade = $this->gradeFromQuery($request);
        }
        $gradeRaw = '';
        if (!$assessment instanceof Assessment && !$grade instanceof GradeLevel) {
            $gradeRaw = $this->gradeRaw(\is_array($replay) ? $request->request->getString('grade') : $request->query->getString('grade'));
        }
        if (\is_array($replay)) {
            $page = $replay['page'];
            $filters = $replay['filters'];
            $selected = $replay['selected'];
            $values = [
                'title' => $replay['title'],
                'instructions' => $replay['instructions'],
                'duration' => $replay['duration'],
            ];
        } else {
            $page = max(1, $request->query->getInt('page', 1));
            $filters = [
                'q' => $this->boundText($request->query->getString('q'), 200),
                'code' => $this->boundText($request->query->getString('code'), 32),
                'outcome' => $this->boundText($request->query->getString('outcome'), 64),
                'difficulty' => $this->difficultyValue($request->query->getString('difficulty')),
            ];
            $selected = $revision instanceof AssessmentRevision ? $this->itemRows($revision) : [];
            $seconds = $revision?->getDurationSeconds();
            $values = [
                'title' => $revision?->getTitle() ?? '',
                'instructions' => $revision?->getInstructions() ?? '',
                'duration' => null !== $seconds ? (string) (int) ceil($seconds / 60) : '',
            ];
        }
        $picker = [];
        $pickerRows = [];
        if ($subject instanceof Subject && $grade instanceof GradeLevel) {
            $picker = $this->questions->searchPublishedPlatform(
                $subject,
                $grade,
                $filters['code'],
                $filters['q'],
                QuestionDifficulty::tryFrom($filters['difficulty']),
                $filters['outcome'],
                self::PAGE_SIZE,
                ($page - 1) * self::PAGE_SIZE,
            );
            foreach ($picker as $question) {
                $questionRevision = $this->questionRevisions->findForQuestionNumber($question, $question->getCurrentRevisionNumber());
                $pickerRows[] = [
                    'id' => $question->getId()->toRfc4122(),
                    'code' => $question->getCode(),
                    'stem' => $questionRevision instanceof QuestionRevision ? ($this->plainLines($questionRevision->getStemContent())[0] ?? '') : '',
                ];
            }
        }

        return $this->renderAdmin('admin/tests/form.html.twig', [
            'assessment' => $assessment,
            'revision' => $revision,
            'subjects' => $this->subjects->findActiveOrdered(),
            'selected' => $selected,
            'values' => $values,
            'picker' => $pickerRows,
            'subject' => $subject,
            'grade' => $grade,
            'grade_raw' => $gradeRaw,
            'page' => $page,
            'filters' => $filters,
            'difficulty_labels' => [
                'very_easy' => 'Çok kolay',
                'easy' => 'Kolay',
                'medium' => 'Orta',
                'hard' => 'Zor',
                'very_hard' => 'Çok zor',
            ],
        ]);
    }

    /**
     * @return array{title: string, instructions: ?string, duration: ?int, grade: GradeLevel, subject: Subject, items: list<array{questionId: string, questionRevisionId: string, position: int, points: string}>}
     */
    private function readInput(Request $request, ?Assessment $assessment): array
    {
        $title = trim($request->request->getString('title'));
        $instructions = trim($request->request->getString('instructions'));
        if ($this->hasMarkup($title) || $this->hasMarkup($instructions)) {
            throw AssessmentException::invalidInput('markup rejected');
        }
        $grade = $assessment?->getGradeLevel() ?? GradeLevel::tryFrom($request->request->getInt('grade'));
        $subject = $assessment?->getSubject() ?? $this->postedSubject($request);
        if (!$grade instanceof GradeLevel || !$subject instanceof Subject) {
            throw AssessmentException::invalidInput('grade or subject missing');
        }
        $minutes = trim($request->request->getString('duration_minutes'));
        $duration = null;
        if ('' !== $minutes) {
            if (1 !== preg_match('/^\d+$/', $minutes) || (int) $minutes < 1 || (int) $minutes > 360) {
                throw AssessmentException::invalidInput('duration rejected');
            }
            $duration = ((int) $minutes) * 60;
        }

        $items = $this->postedItems($request);
        if ([] === $items) {
            throw AssessmentException::emptySection();
        }

        return [
            'title' => $title,
            'instructions' => '' === $instructions ? null : $instructions,
            'duration' => $duration,
            'grade' => $grade,
            'subject' => $subject,
            'items' => $items,
        ];
    }

    /**
     * @return list<array{questionId: string, questionRevisionId: string, position: int, points: string}>
     */
    private function postedItems(Request $request): array
    {
        $raw = $request->request->all('items');
        $items = [];
        foreach ($raw as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $questionId = isset($row['question_id']) && \is_string($row['question_id']) ? $row['question_id'] : '';
            if (1 !== preg_match('/^[0-9a-fA-F-]{36}$/', $questionId)) {
                continue;
            }
            $position = isset($row['position']) ? (int) $row['position'] : 0;
            $points = isset($row['points']) && \is_string($row['points']) && '' !== trim($row['points']) ? trim($row['points']) : '1';
            $items[] = [
                'question_id' => strtolower($questionId),
                'position' => $position,
                'points' => $points,
            ];
        }
        foreach ($request->request->all('add_ids') as $added) {
            if (!\is_string($added) || 1 !== preg_match('/^[0-9a-fA-F-]{36}$/', $added)) {
                continue;
            }
            $items[] = [
                'question_id' => strtolower($added),
                'position' => \count($items) + 1,
                'points' => '1',
            ];
        }
        $items = $this->applyMove($items, $this->moveCommand($request));
        $seenIds = [];
        $seenPositions = [];
        $resolved = [];
        foreach ($items as $item) {
            if (isset($seenIds[$item['question_id']])) {
                throw AssessmentException::duplicateQuestion();
            }
            if ($item['position'] < 1) {
                throw AssessmentException::invalidInput('position rejected');
            }
            if (isset($seenPositions[$item['position']])) {
                throw AssessmentException::invalidInput('Duplicate item position.');
            }
            $seenIds[$item['question_id']] = true;
            $seenPositions[$item['position']] = true;
            $question = $this->questions->findOneById(Uuid::fromString($item['question_id']));
            $revisionNumber = $question?->getCurrentRevisionNumber();
            if (!$question instanceof Question || null === $revisionNumber) {
                throw AssessmentException::notFound();
            }
            $revision = $this->questionRevisions->findForQuestionNumber($question, $revisionNumber);
            if (!$revision instanceof QuestionRevision) {
                throw AssessmentException::notFound();
            }
            $resolved[] = [
                'questionId' => $question->getId()->toRfc4122(),
                'questionRevisionId' => $revision->getId()->toRfc4122(),
                'position' => $item['position'],
                'points' => $item['points'],
            ];
        }
        usort($resolved, static fn (array $left, array $right): int => $left['position'] <=> $right['position']);

        return $resolved;
    }

    private function moveCommand(Request $request): string
    {
        foreach ($request->request->keys() as $key) {
            if (1 === preg_match('/^move_(up|down)_(\d+)$/', $key, $matches)) {
                return $matches[1].'-'.$matches[2];
            }
        }

        return '';
    }

    /**
     * @param list<array{question_id: string, position: int, points: string}> $items
     *
     * @return list<array{question_id: string, position: int, points: string}>
     */
    private function applyMove(array $items, string $move): array
    {
        if (1 !== preg_match('/^(up|down)-(\d+)$/', $move, $matches)) {
            return $items;
        }
        usort($items, static fn (array $left, array $right): int => $left['position'] <=> $right['position']);
        $index = (int) $matches[2];
        $swap = 'up' === $matches[1] ? $index - 1 : $index + 1;
        if (!isset($items[$index], $items[$swap])) {
            return $items;
        }
        $current = $items[$index];
        $items[$index] = $items[$swap];
        $items[$swap] = $current;
        $position = 1;
        foreach ($items as $key => $item) {
            $items[$key]['position'] = $position;
            ++$position;
        }

        return $items;
    }

    /**
     * @param list<array{questionId: string, questionRevisionId: string, position: int, points: string}> $items
     *
     * @return array{title: string, position: int, questionOrderMode: QuestionOrderMode, items: list<array{questionId: string, questionRevisionId: string, position: int, points: string, penaltyPoints: string, required: bool}>}
     */
    private function section(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'questionId' => $item['questionId'],
                'questionRevisionId' => $item['questionRevisionId'],
                'position' => $item['position'],
                'points' => $item['points'],
                'penaltyPoints' => '0',
                'required' => true,
            ];
        }

        return [
            'title' => 'Sorular',
            'position' => 1,
            'questionOrderMode' => QuestionOrderMode::Fixed,
            'items' => $rows,
        ];
    }

    /**
     * @return list<array{position: int, code: string, points: string, stem: string, question_id: string}>
     */
    private function itemRows(AssessmentRevision $revision): array
    {
        $rows = [];
        foreach ($this->orderedItems($revision) as $item) {
            $lines = $this->plainLines($item->getQuestionRevision()->getStemContent());
            $rows[] = [
                'position' => $item->getPosition(),
                'code' => $item->getQuestion()->getCode(),
                'points' => $item->getPoints(),
                'stem' => $lines[0] ?? '',
                'question_id' => $item->getQuestion()->getId()->toRfc4122(),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{position: int, code: string, points: string, stem: list<string>, options: list<string>}>
     */
    private function previewRows(AssessmentRevision $revision): array
    {
        $rows = [];
        foreach ($this->orderedItems($revision) as $item) {
            $options = [];
            foreach ($this->options->findByRevision($item->getQuestionRevision()) as $option) {
                $options[] = $this->plainLines($option->getContent())[0] ?? '';
            }
            $rows[] = [
                'position' => $item->getPosition(),
                'code' => $item->getQuestion()->getCode(),
                'points' => $this->presentation->points($item->getPoints()) ?? $item->getPoints(),
                'stem' => $this->plainLines($item->getQuestionRevision()->getStemContent()),
                'options' => $options,
            ];
        }

        return $rows;
    }

    /**
     * @return list<AssessmentItem>
     */
    private function orderedItems(AssessmentRevision $revision): array
    {
        /** @var list<AssessmentSection> $sections */
        $sections = $this->sections->findBy(['revision' => $revision], ['position' => 'ASC']);
        $items = [];
        foreach ($sections as $section) {
            /** @var list<AssessmentItem> $sectionItems */
            $sectionItems = $this->items->findBy(['section' => $section], ['position' => 'ASC']);
            foreach ($sectionItems as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param list<array{position: int, code: string, points: string, stem: string, question_id: string}> $rows
     *
     * @return list<array{position: int, code: string, points: string, stem: string, question_id: string}>
     */
    private function presentRows(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index]['points'] = $this->presentation->points($row['points']) ?? $row['points'];
        }

        return $rows;
    }

    /**
     * @param list<array{points: string}> $rows
     */
    private function totalPoints(array $rows): string
    {
        $total = '0.00';
        foreach ($rows as $row) {
            $total = bcadd($total, AssessmentScore::normalizePoints($row['points']), 2);
        }

        return $total;
    }

    private function durationLabel(AssessmentRevision $revision): string
    {
        $seconds = $revision->getDurationSeconds();
        if (null === $seconds) {
            $seconds = 0;
            foreach ($this->orderedItems($revision) as $item) {
                $seconds += $item->getQuestionRevision()->getEstimatedSeconds() ?? 0;
            }
        }
        if ($seconds < 1) {
            return 'Belirtilmedi';
        }

        return (string) (int) ceil($seconds / 60).' dk';
    }

    private function currentRevision(Assessment $assessment): AssessmentRevision
    {
        $number = $assessment->getCurrentRevisionNumber();
        $revision = null === $number ? null : $this->revisions->findForAssessmentNumber($assessment, $number);
        if (!$revision instanceof AssessmentRevision) {
            throw $this->createNotFoundException();
        }

        return $revision;
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

        return $assessment;
    }

    private function canEdit(Assessment $assessment): bool
    {
        return AssessmentStatus::Draft === $assessment->getStatus()
            && $this->isGranted(AssessmentPermission::REVISE, $assessment);
    }

    private function canManageAssessmentAccessPolicy(User $actor, Assessment $assessment): bool
    {
        return AssessmentScope::Platform === $assessment->getScope()
            && $this->accessPackageAuthorization->canSetResourceAccessPolicy($actor);
    }

    private function accessPolicyLabel(?ResourceAccessClass $accessClass): string
    {
        return match ($accessClass) {
            ResourceAccessClass::Free => 'Ücretsiz',
            ResourceAccessClass::EntitlementRequired => 'Lisans gerekli',
            null => 'Politika tanımlanmamış',
        };
    }

    /**
     * @param list<Subject> $subjects
     *
     * @return array{status: ?AssessmentStatus, grade: ?GradeLevel, subject: ?Uuid, text: string, blocked: bool}
     */
    private function listFilters(Request $request, array $subjects): array
    {
        $status = AssessmentStatus::tryFrom($request->query->getString('status'));
        $grade = GradeLevel::tryFrom($request->query->getInt('grade'));
        if (0 === $request->query->getInt('grade') && !$request->query->has('grade')) {
            $grade = null;
        }
        $text = trim($request->query->getString('q'));
        if (mb_strlen($text) > 100) {
            $text = mb_substr($text, 0, 100);
        }
        if ($this->hasMarkup($text)) {
            $text = '';
        }
        $subjectRaw = strtolower($request->query->getString('subject_id'));
        $subject = null;
        $blocked = false;
        if ('' !== $subjectRaw) {
            foreach ($subjects as $item) {
                if ($item->getId()->toRfc4122() === $subjectRaw) {
                    $subject = $item->getId();
                    break;
                }
            }
            $blocked = !$subject instanceof Uuid;
        }

        return [
            'status' => $status,
            'grade' => $grade,
            'subject' => $subject,
            'text' => $text,
            'blocked' => $blocked,
        ];
    }

    private function canSeeAll(User $actor): bool
    {
        $roles = $actor->getRoles();

        return \in_array('ROLE_ADMIN', $roles, true)
            || \in_array('ROLE_HEAD_TEACHER', $roles, true)
            || \in_array('ROLE_EXPERT_TEACHER', $roles, true)
            || \in_array('ROLE_SUPER_ADMIN', $roles, true)
            || \in_array('ROLE_MODERATOR', $roles, true);
    }

    private function subjectFromQuery(Request $request): ?Subject
    {
        $raw = $request->query->getString('subject_id');
        if (1 !== preg_match('/^[0-9a-fA-F-]{36}$/', $raw)) {
            return null;
        }

        return $this->subjects->findOneById(Uuid::fromString($raw));
    }

    private function postedSubject(Request $request): ?Subject
    {
        $raw = $request->request->getString('subject_id');
        if (1 !== preg_match('/^[0-9a-fA-F-]{36}$/', $raw)) {
            return null;
        }

        return $this->subjects->findOneById(Uuid::fromString($raw));
    }

    private function gradeFromQuery(Request $request): ?GradeLevel
    {
        if (!$request->query->has('grade')) {
            return GradeLevel::Grade1;
        }

        return GradeLevel::tryFrom($request->query->getInt('grade'));
    }

    private function rejectEditor(Request $request, AssessmentException $exception, ?Assessment $assessment): Response
    {
        if (!$this->keepsEditorInput($exception)) {
            $route = $assessment instanceof Assessment ? 'app_admin_test_edit' : 'app_admin_test_new';
            $params = $assessment instanceof Assessment ? ['id' => $assessment->getId()->toRfc4122()] : [];

            return $this->fail($exception, $route, $params);
        }
        $this->addFlash('error', $this->failureMessage($exception));
        $response = $this->renderForm($request, $assessment, $this->editorReplay($request, $assessment));
        if ($this->getParameter('kernel.debug')) {
            $response->headers->set('X-Test-Failure', $exception->getMessage());
        }

        return $response;
    }

    private function keepsEditorInput(AssessmentException $exception): bool
    {
        return match ($exception->getReason()) {
            AssessmentFailureReason::InvalidInput,
            AssessmentFailureReason::EmptySection,
            AssessmentFailureReason::EmptyAssessment,
            AssessmentFailureReason::DuplicateQuestion,
            AssessmentFailureReason::QuestionNotPublished,
            AssessmentFailureReason::QuestionScopeMismatch,
            AssessmentFailureReason::QuestionRevisionMismatch,
            AssessmentFailureReason::GradeMismatch,
            AssessmentFailureReason::SubjectMismatch,
            AssessmentFailureReason::InvalidPoints,
            AssessmentFailureReason::NotFound,
            AssessmentFailureReason::ScopeMismatch => true,
            default => false,
        };
    }

    /**
     * @return array{
     *     title: string,
     *     instructions: string,
     *     duration: string,
     *     grade: ?GradeLevel,
     *     subject: ?Subject,
     *     selected: list<array{position: string, code: string, points: string, stem: string, question_id: string}>,
     *     filters: array{q: string, code: string, outcome: string, difficulty: string},
     *     page: int
     * }
     */
    private function editorReplay(Request $request, ?Assessment $assessment): array
    {
        $grade = $assessment?->getGradeLevel() ?? GradeLevel::tryFrom($request->request->getInt('grade'));
        $subject = $assessment instanceof Assessment ? $assessment->getSubject() : $this->activeSubject($this->postedSubject($request));
        $page = $request->request->getInt('search_page', 1);

        return [
            'title' => $this->displayText($request->request->getString('title'), 200),
            'instructions' => $this->displayText($request->request->getString('instructions'), 10000),
            'duration' => $this->durationRaw($request->request->getString('duration_minutes')),
            'grade' => $grade,
            'subject' => $subject,
            'selected' => $this->replayRows($request, $grade, $subject),
            'filters' => [
                'q' => $this->boundText($request->request->getString('search_q'), 200),
                'code' => $this->boundText($request->request->getString('search_code'), 32),
                'outcome' => $this->boundText($request->request->getString('search_outcome'), 64),
                'difficulty' => $this->difficultyValue($request->request->getString('search_difficulty')),
            ],
            'page' => max(1, min(50, $page)),
        ];
    }

    /**
     * @return list<array{position: string, code: string, points: string, stem: string, question_id: string}>
     */
    private function replayRows(Request $request, ?GradeLevel $grade, ?Subject $subject): array
    {
        $rows = [];
        $seen = [];
        foreach ($request->request->all('items') as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $questionId = isset($row['question_id']) && \is_string($row['question_id']) ? strtolower($row['question_id']) : '';
            if (isset($seen[$questionId])) {
                continue;
            }
            $resolved = $this->selectableRow(
                $questionId,
                $grade,
                $subject,
                isset($row['position']) && (\is_string($row['position']) || \is_int($row['position'])) ? (string) $row['position'] : '',
                isset($row['points']) && \is_string($row['points']) ? $row['points'] : '',
            );
            if (null === $resolved) {
                continue;
            }
            $seen[$questionId] = true;
            $rows[] = $resolved;
        }
        foreach ($request->request->all('add_ids') as $added) {
            if (!\is_string($added)) {
                continue;
            }
            $questionId = strtolower($added);
            if (isset($seen[$questionId])) {
                continue;
            }
            $resolved = $this->selectableRow($questionId, $grade, $subject, (string) (\count($rows) + 1), '1');
            if (null === $resolved) {
                continue;
            }
            $seen[$questionId] = true;
            $rows[] = $resolved;
        }

        return $rows;
    }

    /**
     * @return array{position: string, code: string, points: string, stem: string, question_id: string}|null
     */
    private function selectableRow(string $questionId, ?GradeLevel $grade, ?Subject $subject, string $position, string $points): ?array
    {
        if (!$grade instanceof GradeLevel || !$subject instanceof Subject || 1 !== preg_match('/^[0-9a-fA-F-]{36}$/', $questionId)) {
            return null;
        }
        $question = $this->questions->findOneById(Uuid::fromString($questionId));
        if (!$question instanceof Question
            || QuestionScope::Platform !== $question->getScope()
            || QuestionStatus::Published !== $question->getStatus()
            || $question->getGradeLevel() !== $grade
            || !$question->getSubject()->getId()->equals($subject->getId())) {
            return null;
        }
        $revision = $this->questionRevisions->findForQuestionNumber($question, $question->getCurrentRevisionNumber());
        if (!$revision instanceof QuestionRevision) {
            return null;
        }

        return [
            'position' => 1 === preg_match('/^\d{1,4}$/', trim($position)) ? trim($position) : '',
            'code' => $question->getCode(),
            'points' => $this->pointsRaw($points),
            'stem' => $this->plainLines($revision->getStemContent())[0] ?? '',
            'question_id' => $question->getId()->toRfc4122(),
        ];
    }

    private function displayText(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }

        return $value;
    }

    private function boundText(string $value, int $max): string
    {
        return $this->hasMarkup($value) ? '' : $this->displayText($value, $max);
    }

    private function durationRaw(string $value): string
    {
        $value = trim($value);

        return 1 === preg_match('/^\d{1,4}$/', $value) ? $value : '';
    }

    private function pointsRaw(string $value): string
    {
        $value = trim($value);
        if ('' === $value || \strlen($value) > 16 || 1 === preg_match('/[\x00-\x1F<>]/', $value)) {
            return '';
        }

        return $value;
    }

    private function gradeRaw(string $value): string
    {
        $value = trim($value);

        return 1 === preg_match('/^\d{1,2}$/', $value) ? $value : '';
    }

    private function activeSubject(?Subject $subject): ?Subject
    {
        return $subject instanceof Subject && SubjectStatus::Active === $subject->getStatus() ? $subject : null;
    }

    private function difficultyValue(string $value): string
    {
        $difficulty = QuestionDifficulty::tryFrom($value);

        return $difficulty instanceof QuestionDifficulty ? $difficulty->value : '';
    }

    private function assertEditorCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('test_editor', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Oturum doğrulaması başarısız.');
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function fail(AssessmentException $exception, string $route, array $params = []): Response
    {
        $this->addFlash('error', $this->failureMessage($exception));
        $response = $this->redirectToRoute($route, $params);
        if ($this->getParameter('kernel.debug')) {
            $response->headers->set('X-Test-Failure', $exception->getMessage());
        }

        return $response;
    }

    private function failureMessage(AssessmentException $exception): string
    {
        if (AssessmentFailureReason::InvalidInput === $exception->getReason()
            && str_contains($exception->getMessage(), 'Duplicate item position')) {
            return 'Aynı sıra numarası iki kez kullanılamaz.';
        }
        if (AssessmentFailureReason::InvalidInput === $exception->getReason() && str_contains($exception->getMessage(), 'markup')) {
            return 'Başlık ve yönerge düz metin olmalıdır.';
        }
        if (AssessmentFailureReason::InvalidInput === $exception->getReason() && str_contains($exception->getMessage(), 'duration')) {
            return 'Süre 1 ile 360 dakika arasında olmalıdır.';
        }
        if (AssessmentFailureReason::InvalidInput === $exception->getReason() && str_contains($exception->getMessage(), 'position rejected')) {
            return 'Sıra 1 veya daha büyük olmalıdır.';
        }
        if (AssessmentFailureReason::InvalidInput === $exception->getReason() && str_contains($exception->getMessage(), 'grade or subject')) {
            return 'Sınıf ve ders seçilmelidir.';
        }

        return match ($exception->getReason()) {
            AssessmentFailureReason::QuestionNotPublished => 'Yalnız yayımlanmış sorular eklenebilir.',
            AssessmentFailureReason::GradeMismatch => 'Soru sınıfı testin sınıfıyla aynı olmalı.',
            AssessmentFailureReason::SubjectMismatch => 'Soru dersi testin dersiyle aynı olmalı.',
            AssessmentFailureReason::DuplicateQuestion => 'Bu soru teste zaten ekli.',
            AssessmentFailureReason::EmptySection,
            AssessmentFailureReason::EmptyAssessment => 'En az bir soru ekleyin.',
            AssessmentFailureReason::InvalidPoints => 'Puan 0\'dan büyük olmalıdır.',
            AssessmentFailureReason::ReviewSeparation => 'Kendi hazırladığınız testi yayımlayamazsınız.',
            AssessmentFailureReason::Unauthorized => 'Bu işlem için yetkiniz yok.',
            AssessmentFailureReason::Conflict => 'Bu test başka bir işlemle güncellendi. Sayfayı yenileyip yeniden deneyin.',
            AssessmentFailureReason::InvalidTransition,
            AssessmentFailureReason::Immutable => 'Yayımlanmış test içeriği değiştirilemez.',
            default => 'Test kaydedilemedi.',
        };
    }

    private function hasMarkup(string $value): bool
    {
        return str_contains($value, '<') || str_contains($value, '>');
    }

    /**
     * @param array<string, mixed>|null $document
     *
     * @return list<string>
     */
    private function plainLines(?array $document): array
    {
        if (null === $document) {
            return [];
        }
        $blocks = $document['blocks'] ?? null;
        if (!\is_array($blocks)) {
            return [];
        }
        $lines = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $text = $block['text'] ?? null;
            if (\is_string($text) && '' !== trim($text)) {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            'draft' => 'Taslak',
            'in_review' => 'İncelemede',
            'published' => 'Yayında',
            'archived' => 'Arşivlenmiş',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function typeLabels(): array
    {
        return [
            AssessmentType::Quiz->value => 'Kısa test',
            AssessmentType::PracticeTest->value => 'Deneme',
            AssessmentType::MockExam->value => 'Sınav provası',
            AssessmentType::Diagnostic->value => 'Tarama',
            AssessmentType::HomeworkBlueprint->value => 'Ödev planı',
        ];
    }

    private function defaultReason(string $action): string
    {
        return match ($action) {
            'return' => ContentWorkflowReason::RETURN_DRAFT,
            'publish' => ContentWorkflowReason::PUBLISH,
            'archive' => ContentWorkflowReason::ARCHIVE,
            default => ContentWorkflowReason::SUBMIT_REVIEW,
        };
    }

    private function successMessage(string $action): string
    {
        return match ($action) {
            'submit' => 'Test incelemeye gönderildi.',
            'return' => 'Test taslağa döndürüldü.',
            'publish' => 'Test yayınlandı.',
            'archive' => 'Test arşivlendi.',
            default => 'Test kaydedildi.',
        };
    }

    private function assertPlacementCsrf(Request $request, string $id, string $prefix): void
    {
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid($prefix.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }
    }

    private function requireAssessmentPlacement(string $id): CatalogTopicAssessment
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        $placement = $this->assessmentPlacementRepo->findOneById($uuid);
        if (!$placement instanceof CatalogTopicAssessment) {
            throw $this->createNotFoundException();
        }

        return $placement;
    }

    private function canCreateAssessmentPlacement(Assessment $assessment): bool
    {
        return $this->isGranted(CatalogTopicAssessmentPermission::CREATE)
            && AssessmentScope::Platform === $assessment->getScope()
            && null === $assessment->getInstitution()
            && AssessmentStatus::Archived !== $assessment->getStatus();
    }

    /**
     * @return list<CatalogTopic>
     */
    private function bindableTopicsForAssessment(Assessment $assessment): array
    {
        $subject = $assessment->getSubject();
        if (!$subject instanceof Subject) {
            return [];
        }
        if (AssessmentScope::Platform !== $assessment->getScope() || null !== $assessment->getInstitution()) {
            return [];
        }
        $grade = $assessment->getGradeLevel();
        $topics = $this->catalogTopics->findBindableForCanonicalSubject($subject);

        return array_values(array_filter(
            $topics,
            static fn (CatalogTopic $topic): bool => $topic->getUnit()->getSubject()->getGradeLevel() === $grade,
        ));
    }

    private function isBindableTopicForAssessment(CatalogTopic $topic, Assessment $assessment): bool
    {
        foreach ($this->bindableTopicsForAssessment($assessment) as $bindable) {
            if ($bindable->getId()->equals($topic->getId())) {
                return true;
            }
        }

        return false;
    }
}

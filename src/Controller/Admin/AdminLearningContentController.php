<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\LearningContentCreateRequest;
use App\Dto\SubjectCreateRequest;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\LearningContent;
use App\Entity\LearningContentRevision;
use App\Entity\Subject;
use App\Enum\GradeLevel;
use App\Enum\LearningContentFailureReason;
use App\Enum\LearningContentScope;
use App\Enum\LearningContentType;
use App\Enum\ResourceAccessClass;
use App\Exception\AccessEntitlementException;
use App\Exception\CommerceException;
use App\Exception\LearningContentException;
use App\Exception\SubjectException;
use App\Form\LearningContentCreateFormType;
use App\Form\SubjectCreateFormType;
use App\LearningContent\Content\LearningContentDocument;
use App\Repository\CurriculumLearningOutcomeRepository;
use App\Repository\LearningContentAccessPolicyRepository;
use App\Repository\SubjectRepository;
use App\Security\AdminPermission;
use App\Service\AccessPackageManager;
use App\Service\Admin\AdminLearningContentQuery;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\LearningContentRevisionFormMapper;
use App\Service\LearningContentManager;
use App\Service\SubjectManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminLearningContentController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminLearningContentQuery $query,
        private readonly LearningContentManager $contents,
        private readonly AccessPackageManager $accessPackages,
        private readonly SubjectManager $subjectManager,
        private readonly SubjectRepository $subjects,
        private readonly CurriculumLearningOutcomeRepository $outcomes,
        private readonly LearningContentAccessPolicyRepository $policies,
        private readonly LearningContentRevisionFormMapper $revisionFormMapper,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/icerikler', name: 'app_admin_learning_contents', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_VIEW)]
    public function index(Request $request): Response
    {
        try {
            $result = $this->query->listContents($this->requireActorId(), [
                'q' => $request->query->getString('q'),
                'status' => $request->query->getString('status'),
                'subject_id' => $request->query->getString('subject_id'),
                'grade' => $request->query->getString('grade'),
                'page' => $request->query->getInt('page', 1),
                'page_size' => $request->query->getInt('page_size', 25),
            ]);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/learning_contents/list.html.twig', [
            'result' => $result,
            'filters' => [
                'q' => $request->query->getString('q'),
                'status' => $request->query->getString('status'),
                'subject_id' => $request->query->getString('subject_id'),
                'grade' => $request->query->getString('grade'),
            ],
            'subjects' => $this->subjects->findActiveOrdered(),
            'can_manage' => $this->isGranted(AdminPermission::ADMIN_LEARNING_CONTENT_MANAGE),
            'can_create_subject' => $this->isGranted(AdminPermission::ADMIN_PAYMENT_OPS),
        ]);
    }

    #[Route('/yonetim/icerikler/yeni', name: 'app_admin_learning_content_new', methods: ['GET', 'POST'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_MANAGE)]
    public function create(Request $request): Response
    {
        $subjectChoices = $this->subjectChoiceMap();
        $outcomeChoices = $this->outcomeChoiceMap();
        $dto = new LearningContentCreateRequest();
        $form = $this->createForm(LearningContentCreateFormType::class, $dto, [
            'subject_choices' => $subjectChoices,
            'outcome_choices' => $outcomeChoices,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            if ($form->isValid()) {
                try {
                    $gradeLevel = $dto->gradeLevel;
                    $contentType = $dto->contentType;
                    if (!$gradeLevel instanceof GradeLevel || !$contentType instanceof LearningContentType) {
                        $this->addFlash('error', 'Sınıf seviyesi ve içerik türü zorunludur.');
                    } else {
                        $subject = $this->requireActiveSubject((string) $dto->subjectId);
                        $outcome = $this->requireOutcomeForSubject((string) $dto->learningOutcomeId, $subject);
                        $content = $this->contents->createDraft(
                            $this->requireActorUser(),
                            LearningContentScope::Platform,
                            null,
                            $subject,
                            $gradeLevel,
                            $contentType,
                            (string) $dto->code,
                            (string) $dto->title,
                            $dto->summary,
                            LearningContentDocument::paragraph('[Taslak]'),
                            [['learningOutcome' => $outcome, 'isPrimary' => true]],
                            'admin_create_draft',
                        );
                        $this->addFlash('success', 'Taslak içerik oluşturuldu.');

                        return $this->redirectToRoute('app_admin_learning_content', [
                            'id' => $content->getId()->toRfc4122(),
                        ]);
                    }
                } catch (LearningContentException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        return $this->renderAdmin('admin/learning_contents/create.html.twig', [
            'form' => $form,
            'can_create_subject' => $this->isGranted(AdminPermission::ADMIN_PAYMENT_OPS),
        ]);
    }

    #[Route('/yonetim/icerikler/konu-alani/yeni', name: 'app_admin_learning_content_subject_new', methods: ['GET', 'POST'])]
    #[IsGranted(AdminPermission::ADMIN_PAYMENT_OPS)]
    public function createSubject(Request $request): Response
    {
        $dto = new SubjectCreateRequest();
        $form = $this->createForm(SubjectCreateFormType::class, $dto);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            if ($form->isValid()) {
                try {
                    $subject = $this->subjectManager->create(
                        $this->requireActorUser(),
                        (string) $dto->code,
                        (string) $dto->name,
                        'admin_subject_create',
                    );
                    $this->addFlash('success', 'Konu alanı oluşturuldu.');

                    return $this->redirectToRoute('app_admin_learning_content_new', [
                        'subject_id' => $subject->getId()->toRfc4122(),
                    ]);
                } catch (SubjectException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        return $this->renderAdmin('admin/learning_contents/subject_create.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/yonetim/icerikler/{id}', name: 'app_admin_learning_content', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_VIEW)]
    public function detail(string $id): Response
    {
        $content = $this->requireContent($id);
        $policy = $this->policies->findForContent($content->getId());

        return $this->renderAdmin('admin/learning_contents/detail.html.twig', [
            'content' => $content,
            'policy' => $policy,
            'can_manage' => $this->isGranted(AdminPermission::ADMIN_LEARNING_CONTENT_MANAGE),
            'can_set_policy' => $this->isGranted(AdminPermission::ADMIN_CATALOG_MAP_CANONICAL),
        ]);
    }

    #[Route('/yonetim/icerikler/{id}/erisim', name: 'app_admin_learning_content_policy', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_MANAGE)]
    public function setPolicy(Request $request, string $id): Response
    {
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('learning_content_policy_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }

        $content = $this->requireContent($id);
        $accessClassRaw = (string) $request->request->get('access_class', '');
        $accessClass = ResourceAccessClass::tryFrom($accessClassRaw);
        if (!$accessClass instanceof ResourceAccessClass) {
            $this->addFlash('error', 'Geçersiz erişim politikası.');

            return $this->redirectToRoute('app_admin_learning_content', ['id' => $id]);
        }
        if (ResourceAccessClass::Free === $accessClass && '1' !== (string) $request->request->get('confirm_free')) {
            $this->addFlash('error', 'Ücretsiz erişim için onay kutusu zorunludur.');

            return $this->redirectToRoute('app_admin_learning_content', ['id' => $id]);
        }

        try {
            $this->accessPackages->setLearningContentAccessPolicy(
                $content,
                $this->requireActorUser(),
                $accessClass,
                ResourceAccessClass::Free === $accessClass ? 'admin_set_free' : 'admin_set_entitlement',
            );
            $this->addFlash('success', 'Erişim politikası güncellendi.');
        } catch (AccessEntitlementException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_learning_content', ['id' => $id]);
    }

    #[Route('/yonetim/icerikler/{id}/revision', name: 'app_admin_learning_content_revision', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_MANAGE)]
    public function revisionEdit(string $id): Response
    {
        $content = $this->requireContent($id);
        $revision = $this->requireCurrentRevision($content);

        try {
            $editorBlocks = $this->revisionFormMapper->editorRowsFromStructuredContent($revision->getStructuredContent());
        } catch (LearningContentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin_learning_content', ['id' => $id]);
        }

        return $this->renderAdmin('admin/learning_contents/revision_edit.html.twig', [
            'content' => $content,
            'revision' => $revision,
            'editor_blocks' => $editorBlocks,
            'type_choices' => $this->revisionFormMapper->typeChoices(),
            'csrf_id' => 'learning_content_revision_'.$id,
        ]);
    }

    #[Route('/yonetim/icerikler/{id}/revision/clone', name: 'app_admin_learning_content_revision_clone', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_MANAGE)]
    public function revisionClone(Request $request, string $id): Response
    {
        $this->assertRevisionCsrf($request, $id);
        $content = $this->requireContent($id);
        $revision = $this->requireCurrentRevision($content);
        if (!$revision->isSealed()) {
            $this->addFlash('error', 'Yalnız mühürlü sürümler klonlanabilir. Taslak üzerinde doğrudan düzenleyin.');

            return $this->redirectToRoute('app_admin_learning_content_revision', ['id' => $id]);
        }

        try {
            $this->contents->cloneAsNewRevision($content, $this->requireActorUser(), 'admin_revision_clone');
            $this->addFlash('success', 'Yeni taslak sürüm oluşturuldu.');
        } catch (LearningContentException $e) {
            $this->flashLearningContentException($e);
        }

        return $this->redirectToRoute('app_admin_learning_content_revision', ['id' => $id]);
    }

    #[Route('/yonetim/icerikler/{id}/revision/save', name: 'app_admin_learning_content_revision_save', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_MANAGE)]
    public function revisionSave(Request $request, string $id): Response
    {
        $this->assertRevisionCsrf($request, $id);
        $content = $this->requireContent($id);
        $revision = $this->requireCurrentRevision($content);

        if (!$this->assertOptimisticRevision($request, $revision)) {
            return $this->redirectToRoute('app_admin_learning_content_revision', ['id' => $id]);
        }

        $postedBlocks = $request->request->all('blocks');

        try {
            $document = $this->revisionFormMapper->documentFromPostedBlocks($postedBlocks);
            $this->contents->updateUnsealedRevision(
                $revision,
                $this->requireActorUser(),
                $document,
                'admin_revision_save',
            );
            $this->addFlash('success', 'Sürüm kaydedildi.');
        } catch (LearningContentException $e) {
            $this->flashLearningContentException($e);
        }

        return $this->redirectToRoute('app_admin_learning_content_revision', ['id' => $id]);
    }

    #[Route('/yonetim/icerikler/{id}/revision/add-block', name: 'app_admin_learning_content_revision_add_block', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_MANAGE)]
    public function revisionAddBlock(Request $request, string $id): Response
    {
        $this->assertRevisionCsrf($request, $id);
        $content = $this->requireContent($id);
        $revision = $this->requireCurrentRevision($content);

        if (!$this->assertOptimisticRevision($request, $revision)) {
            return $this->redirectToRoute('app_admin_learning_content_revision', ['id' => $id]);
        }

        $type = (string) $request->request->get('block_type', '');

        try {
            $empty = $this->revisionFormMapper->emptyBlock($type);
            $structured = $revision->getStructuredContent();
            $this->revisionFormMapper->editorRowsFromStructuredContent($structured);
            $blocks = $structured['blocks'] ?? [];
            if (!\is_array($blocks)) {
                throw LearningContentException::contentInvalid('Geçersiz içerik gövdesi.');
            }
            $blocks[] = $empty;
            $document = $this->revisionFormMapper->documentFromDomainBlocks($blocks);
            $this->contents->updateUnsealedRevision(
                $revision,
                $this->requireActorUser(),
                $document,
                'admin_revision_add_block',
            );
            $this->addFlash('success', 'Blok eklendi.');
        } catch (LearningContentException $e) {
            $this->flashLearningContentException($e);
        }

        return $this->redirectToRoute('app_admin_learning_content_revision', ['id' => $id]);
    }

    #[Route('/yonetim/icerikler/{id}/revision/remove-block', name: 'app_admin_learning_content_revision_remove_block', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_MANAGE)]
    public function revisionRemoveBlock(Request $request, string $id): Response
    {
        $this->assertRevisionCsrf($request, $id);
        $content = $this->requireContent($id);
        $revision = $this->requireCurrentRevision($content);

        if (!$this->assertOptimisticRevision($request, $revision)) {
            return $this->redirectToRoute('app_admin_learning_content_revision', ['id' => $id]);
        }

        $index = $request->request->getInt('index', -1);

        try {
            $structured = $revision->getStructuredContent();
            $this->revisionFormMapper->editorRowsFromStructuredContent($structured);
            $blocks = $structured['blocks'] ?? [];
            if (!\is_array($blocks) || !isset($blocks[$index])) {
                throw LearningContentException::contentInvalid('Kaldırılacak blok bulunamadı.');
            }
            unset($blocks[$index]);
            $document = $this->revisionFormMapper->documentFromDomainBlocks(array_values($blocks));
            $this->contents->updateUnsealedRevision(
                $revision,
                $this->requireActorUser(),
                $document,
                'admin_revision_remove_block',
            );
            $this->addFlash('success', 'Blok kaldırıldı.');
        } catch (LearningContentException $e) {
            $this->flashLearningContentException($e);
        }

        return $this->redirectToRoute('app_admin_learning_content_revision', ['id' => $id]);
    }

    #[Route('/yonetim/icerikler/{id}/revision/move-block', name: 'app_admin_learning_content_revision_move_block', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_MANAGE)]
    public function revisionMoveBlock(Request $request, string $id): Response
    {
        $this->assertRevisionCsrf($request, $id);
        $content = $this->requireContent($id);
        $revision = $this->requireCurrentRevision($content);

        if (!$this->assertOptimisticRevision($request, $revision)) {
            return $this->redirectToRoute('app_admin_learning_content_revision', ['id' => $id]);
        }

        $index = $request->request->getInt('index', -1);
        $direction = (string) $request->request->get('direction', '');

        try {
            $structured = $revision->getStructuredContent();
            $this->revisionFormMapper->editorRowsFromStructuredContent($structured);
            $blocks = $structured['blocks'] ?? [];
            if (!\is_array($blocks) || !isset($blocks[$index])) {
                throw LearningContentException::contentInvalid('Taşınacak blok bulunamadı.');
            }
            $blocks = array_values($blocks);
            $swapWith = 'up' === $direction ? $index - 1 : ('down' === $direction ? $index + 1 : -1);
            if ($swapWith < 0 || $swapWith >= \count($blocks)) {
                throw LearningContentException::contentInvalid('Blok taşınamaz.');
            }
            $tmp = $blocks[$index];
            $blocks[$index] = $blocks[$swapWith];
            $blocks[$swapWith] = $tmp;
            $document = $this->revisionFormMapper->documentFromDomainBlocks($blocks);
            $this->contents->updateUnsealedRevision(
                $revision,
                $this->requireActorUser(),
                $document,
                'admin_revision_move_block',
            );
            $this->addFlash('success', 'Blok sırası güncellendi.');
        } catch (LearningContentException $e) {
            $this->flashLearningContentException($e);
        }

        return $this->redirectToRoute('app_admin_learning_content_revision', ['id' => $id]);
    }

    #[Route('/yonetim/icerikler/{id}/revision/taslak-gorunum', name: 'app_admin_learning_content_revision_preview', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_LEARNING_CONTENT_VIEW)]
    public function revisionPreview(string $id): Response
    {
        $content = $this->requireContent($id);
        $revision = $this->requireCurrentRevision($content);
        $structured = $revision->getStructuredContent();
        $blocks = $structured['blocks'] ?? [];
        if (!\is_array($blocks)) {
            $blocks = [];
        }

        return $this->renderAdmin('admin/learning_contents/revision_preview.html.twig', [
            'content' => $content,
            'revision' => $revision,
            'preview_blocks' => $blocks,
            'can_manage' => $this->isGranted(AdminPermission::ADMIN_LEARNING_CONTENT_MANAGE),
        ]);
    }

    private function requireContent(string $id): LearningContent
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        try {
            return $this->query->requireVisibleContent($this->requireActorId(), $uuid);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }
    }

    private function requireActiveSubject(string $id): Subject
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw LearningContentException::invalidInput('Geçersiz konu alanı.');
        }
        $subject = $this->subjects->findOneById($uuid);
        if (!$subject instanceof Subject || \App\Enum\SubjectStatus::Active !== $subject->getStatus()) {
            throw LearningContentException::invalidInput('Yalnız aktif canonical konu alanı seçilebilir.');
        }

        return $subject;
    }

    private function requireOutcomeForSubject(string $id, Subject $subject): CurriculumLearningOutcome
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw LearningContentException::invalidInput('Geçersiz öğrenme kazanımı.');
        }
        $outcome = $this->outcomes->findOneById($uuid);
        if (!$outcome instanceof CurriculumLearningOutcome) {
            throw LearningContentException::invalidInput('Öğrenme kazanımı bulunamadı.');
        }
        if (!$outcome->getCurriculumProgram()->getSubject()->getId()->equals($subject->getId())) {
            throw LearningContentException::invalidInput('Kazanım seçilen konu alanına ait değil.');
        }

        return $outcome;
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

    private function requireCurrentRevision(LearningContent $content): LearningContentRevision
    {
        $revision = $content->getCurrentRevision();
        if (!$revision instanceof LearningContentRevision) {
            throw $this->createNotFoundException('İçeriğin güncel sürümü bulunamadı.');
        }

        return $revision;
    }

    private function assertRevisionCsrf(Request $request, string $id): void
    {
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('learning_content_revision_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }
    }

    private function assertOptimisticRevision(Request $request, LearningContentRevision $revision): bool
    {
        $expectedId = (string) $request->request->get('expected_revision_id', '');
        if ('' === $expectedId || $expectedId !== $revision->getId()->toRfc4122()) {
            $this->addFlash('error', 'Çakışma: sürüm değişmiş veya mühürlü. Değişiklikler kaydedilmedi; sayfayı yenileyip yeniden deneyin.');

            return false;
        }

        $expectedHash = $request->request->get('expected_content_hash');
        if (\is_string($expectedHash) && '' !== $expectedHash && $expectedHash !== $revision->getContentHash()) {
            $this->addFlash('error', 'Çakışma: içerik başka bir işlemle güncellenmiş. Değişiklikler kaydedilmedi.');

            return false;
        }

        if ($revision->isSealed()) {
            $this->addFlash('error', 'Mühürlü sürüm değiştirilemez. Önce yeni sürüm klonlayın.');

            return false;
        }

        // Non-current pointer (stale entity / race) — manager also checks; surface clearly here.
        $content = $revision->getContent();
        if ($content->getCurrentRevisionNumber() !== $revision->getRevisionNumber()) {
            $this->addFlash('error', 'Çakışma: bu sürüm artık güncel değil. Değişiklikler kaydedilmedi.');

            return false;
        }

        return true;
    }

    private function flashLearningContentException(LearningContentException $e): void
    {
        $message = match ($e->getReason()) {
            LearningContentFailureReason::Conflict => 'Çakışma: sürüm değişmiş veya mühürlü. Değişiklikler kaydedilmedi.',
            LearningContentFailureReason::RevisionSealed => 'Mühürlü sürüm değiştirilemez. Önce yeni sürüm klonlayın.',
            LearningContentFailureReason::Unauthorized => 'Bu işlem için yetkiniz yok.',
            LearningContentFailureReason::NotFound => 'Kayıt bulunamadı.',
            default => $e->getMessage(),
        };
        $this->addFlash('error', $message);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\LearningContentCreateRequest;
use App\Dto\SubjectCreateRequest;
use App\Entity\CurriculumLearningOutcome;
use App\Entity\LearningContent;
use App\Entity\Subject;
use App\Enum\GradeLevel;
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
}

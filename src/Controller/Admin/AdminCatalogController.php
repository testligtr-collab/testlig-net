<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\CatalogSubjectRequest;
use App\Dto\CatalogTopicRequest;
use App\Dto\CatalogUnitRequest;
use App\Entity\CatalogSubject;
use App\Entity\CatalogTopic;
use App\Entity\CatalogTopicLesson;
use App\Entity\CatalogUnit;
use App\Entity\LearningContent;
use App\Enum\CatalogPublicationStatus;
use App\Exception\CatalogException;
use App\Exception\LearningContentException;
use App\Form\CatalogSubjectFormType;
use App\Form\CatalogTopicFormType;
use App\Form\CatalogUnitFormType;
use App\Presentation\ContentWorkflowReason;
use App\Presentation\PlacementPosition;
use App\Repository\CatalogSubjectRepository;
use App\Repository\CatalogTopicLessonRepository;
use App\Repository\CatalogTopicRepository;
use App\Repository\CatalogUnitRepository;
use App\Repository\LearningContentRepository;
use App\Repository\SubjectRepository;
use App\Security\AdminPermission;
use App\Security\CatalogTopicLessonPermission;
use App\Service\Admin\AdminNavBuilder;
use App\Service\CatalogTopicLessonManager;
use App\Service\CatalogWriteService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminCatalogController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly CatalogSubjectRepository $subjects,
        private readonly CatalogUnitRepository $units,
        private readonly CatalogTopicRepository $topics,
        private readonly CatalogWriteService $writer,
        private readonly SubjectRepository $canonicalSubjects,
        private readonly CatalogTopicLessonManager $placements,
        private readonly CatalogTopicLessonRepository $placementRepo,
        private readonly LearningContentRepository $learningContents,
        private readonly ContentWorkflowReason $workflowReason,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/mufredat', name: 'app_admin_catalog', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_VIEW)]
    public function index(): Response
    {
        return $this->renderAdmin('admin/catalog/index.html.twig', [
            'subjects' => $this->subjects->findAllOrdered(),
            'can_manage' => $this->isGranted(AdminPermission::ADMIN_CATALOG_MANAGE),
        ]);
    }

    #[Route('/yonetim/mufredat/ders/yeni', name: 'app_admin_catalog_subject_new', methods: ['GET', 'POST'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MANAGE)]
    public function subjectNew(Request $request): Response
    {
        $dto = new CatalogSubjectRequest();
        $form = $this->createForm(CatalogSubjectFormType::class, $dto);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            if ($form->isValid()) {
                if (null === $dto->gradeLevel) {
                    $this->addFlash('error', 'Sınıf seviyesi zorunludur.');
                } else {
                    try {
                        $subject = $this->writer->createSubject(
                            $dto->gradeLevel,
                            $dto->name,
                            $dto->description,
                            (int) $dto->position,
                        );
                        $this->addFlash('success', 'Ders oluşturuldu (taslak).');

                        return $this->redirectToRoute('app_admin_catalog_subject', ['id' => $subject->getId()->toRfc4122()]);
                    } catch (CatalogException $e) {
                        $this->addFlash('error', $e->getMessage());
                    }
                }
            }
        }

        return $this->renderAdmin('admin/catalog/subject_form.html.twig', [
            'form' => $form,
            'subject' => null,
            'title' => 'Yeni ders',
        ]);
    }

    #[Route('/yonetim/mufredat/ders/{id}', name: 'app_admin_catalog_subject', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function subjectShow(string $id): Response
    {
        if (!$this->isGranted(AdminPermission::ADMIN_CATALOG_VIEW)
            && !$this->isGranted(AdminPermission::ADMIN_CATALOG_MAP_CANONICAL)) {
            throw $this->createAccessDeniedException();
        }
        $subject = $this->requireSubject($id);

        return $this->renderAdmin('admin/catalog/subject_show.html.twig', [
            'subject' => $subject,
            'units' => $this->units->findBySubjectOrdered($subject),
            'can_manage' => $this->isGranted(AdminPermission::ADMIN_CATALOG_MANAGE),
            'can_map_canonical' => $this->isGranted(AdminPermission::ADMIN_CATALOG_MAP_CANONICAL),
            'canonical_subjects' => $this->canonicalSubjects->findActiveOrdered(),
        ]);
    }

    #[Route('/yonetim/mufredat/ders/{id}/canonical', name: 'app_admin_catalog_subject_canonical', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MAP_CANONICAL)]
    public function subjectCanonicalMap(Request $request, string $id): Response
    {
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('catalog_canonical_map_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }
        $raw = trim((string) $request->request->get('canonical_subject_id', ''));
        $canonicalId = null;
        if ('' !== $raw) {
            try {
                $canonicalId = Uuid::fromString($raw);
            } catch (\InvalidArgumentException) {
                $this->addFlash('error', 'Geçersiz canonical konu alanı kimliği.');

                return $this->redirectToRoute('app_admin_catalog_subject', ['id' => $id]);
            }
        }
        try {
            $this->writer->assignCanonicalSubject(
                $this->requireActorUser(),
                Uuid::fromString($id),
                $canonicalId,
                'map_canonical',
            );
            $this->addFlash('success', null === $canonicalId ? 'Canonical eşleme kaldırıldı.' : 'Canonical eşleme kaydedildi.');
        } catch (CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_catalog_subject', ['id' => $id]);
    }

    #[Route('/yonetim/mufredat/ders/{id}/duzenle', name: 'app_admin_catalog_subject_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MANAGE)]
    public function subjectEdit(Request $request, string $id): Response
    {
        $subject = $this->requireSubject($id);
        $dto = new CatalogSubjectRequest();
        $dto->gradeLevel = $subject->getGradeLevel();
        $dto->name = $subject->getName();
        $dto->description = $subject->getDescription();
        $dto->position = $subject->getPosition();
        $form = $this->createForm(CatalogSubjectFormType::class, $dto, ['lock_grade' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            if ($form->isValid()) {
                try {
                    $this->writer->updateSubject($subject->getId(), $dto->name, $dto->description, (int) $dto->position);
                    $this->addFlash('success', 'Ders güncellendi.');

                    return $this->redirectToRoute('app_admin_catalog_subject', ['id' => $id]);
                } catch (CatalogException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        return $this->renderAdmin('admin/catalog/subject_form.html.twig', [
            'form' => $form,
            'subject' => $subject,
            'title' => 'Dersi düzenle',
        ]);
    }

    #[Route('/yonetim/mufredat/ders/{id}/yayimla', name: 'app_admin_catalog_subject_publish', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MANAGE)]
    public function subjectPublish(Request $request, string $id): Response
    {
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('catalog_publish_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }
        try {
            $this->writer->publishSubject(Uuid::fromString($id));
            $this->addFlash('success', 'Ders yayımlandı.');
        } catch (CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_catalog_subject', ['id' => $id]);
    }

    #[Route('/yonetim/mufredat/ders/{id}/arsivle', name: 'app_admin_catalog_subject_archive', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MANAGE)]
    public function subjectArchive(Request $request, string $id): Response
    {
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('catalog_archive_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }
        try {
            $this->writer->archiveSubject(Uuid::fromString($id));
            $this->addFlash('success', 'Ders arşivlendi.');
        } catch (CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_catalog_subject', ['id' => $id]);
    }

    #[Route('/yonetim/mufredat/ders/{id}/unite/yeni', name: 'app_admin_catalog_unit_new', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MANAGE)]
    public function unitNew(Request $request, string $id): Response
    {
        $subject = $this->requireSubject($id);
        $dto = new CatalogUnitRequest();
        $form = $this->createForm(CatalogUnitFormType::class, $dto);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            if ($form->isValid()) {
                try {
                    $unit = $this->writer->createUnit($subject->getId(), $dto->name, $dto->description, (int) $dto->position);
                    $this->addFlash('success', 'Ünite oluşturuldu (taslak).');

                    return $this->redirectToRoute('app_admin_catalog_unit', ['id' => $unit->getId()->toRfc4122()]);
                } catch (CatalogException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        return $this->renderAdmin('admin/catalog/unit_form.html.twig', [
            'form' => $form,
            'subject' => $subject,
            'unit' => null,
            'title' => 'Yeni ünite',
        ]);
    }

    #[Route('/yonetim/mufredat/unite/{id}', name: 'app_admin_catalog_unit', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_VIEW)]
    public function unitShow(string $id): Response
    {
        $unit = $this->requireUnit($id);

        return $this->renderAdmin('admin/catalog/unit_show.html.twig', [
            'unit' => $unit,
            'subject' => $unit->getSubject(),
            'topics' => $this->topics->findByUnitOrdered($unit),
            'can_manage' => $this->isGranted(AdminPermission::ADMIN_CATALOG_MANAGE),
        ]);
    }

    #[Route('/yonetim/mufredat/unite/{id}/duzenle', name: 'app_admin_catalog_unit_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MANAGE)]
    public function unitEdit(Request $request, string $id): Response
    {
        $unit = $this->requireUnit($id);
        $dto = new CatalogUnitRequest();
        $dto->name = $unit->getName();
        $dto->description = $unit->getDescription();
        $dto->position = $unit->getPosition();
        $form = $this->createForm(CatalogUnitFormType::class, $dto);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            if ($form->isValid()) {
                try {
                    $this->writer->updateUnit($unit->getId(), $dto->name, $dto->description, (int) $dto->position);
                    $this->addFlash('success', 'Ünite güncellendi.');

                    return $this->redirectToRoute('app_admin_catalog_unit', ['id' => $id]);
                } catch (CatalogException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        return $this->renderAdmin('admin/catalog/unit_form.html.twig', [
            'form' => $form,
            'subject' => $unit->getSubject(),
            'unit' => $unit,
            'title' => 'Üniteyi düzenle',
        ]);
    }

    #[Route('/yonetim/mufredat/unite/{id}/yayimla', name: 'app_admin_catalog_unit_publish', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MANAGE)]
    public function unitPublish(Request $request, string $id): Response
    {
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('catalog_publish_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }
        try {
            $this->writer->publishUnit(Uuid::fromString($id));
            $this->addFlash('success', 'Ünite yayımlandı.');
        } catch (CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_catalog_unit', ['id' => $id]);
    }

    #[Route('/yonetim/mufredat/unite/{id}/arsivle', name: 'app_admin_catalog_unit_archive', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MANAGE)]
    public function unitArchive(Request $request, string $id): Response
    {
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('catalog_archive_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }
        try {
            $this->writer->archiveUnit(Uuid::fromString($id));
            $this->addFlash('success', 'Ünite arşivlendi.');
        } catch (CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_catalog_unit', ['id' => $id]);
    }

    #[Route('/yonetim/mufredat/unite/{id}/konu/yeni', name: 'app_admin_catalog_topic_new', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MANAGE)]
    public function topicNew(Request $request, string $id): Response
    {
        $unit = $this->requireUnit($id);
        $dto = new CatalogTopicRequest();
        $form = $this->createForm(CatalogTopicFormType::class, $dto);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            if ($form->isValid()) {
                try {
                    $topic = $this->writer->createTopic($unit->getId(), $dto->name, $dto->summary, (int) $dto->position, $dto->estimatedMinutes);
                    $this->addFlash('success', 'Konu oluşturuldu (taslak).');

                    return $this->redirectToRoute('app_admin_catalog_unit', ['id' => $id]);
                } catch (CatalogException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        return $this->renderAdmin('admin/catalog/topic_form.html.twig', [
            'form' => $form,
            'unit' => $unit,
            'topic' => null,
            'title' => 'Yeni konu',
        ]);
    }

    #[Route('/yonetim/mufredat/konu/{id}/duzenle', name: 'app_admin_catalog_topic_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MANAGE)]
    public function topicEdit(Request $request, string $id): Response
    {
        $topic = $this->requireTopic($id);
        $dto = new CatalogTopicRequest();
        $dto->name = $topic->getName();
        $dto->summary = $topic->getSummary();
        $dto->position = $topic->getPosition();
        $dto->estimatedMinutes = $topic->getEstimatedMinutes();
        $form = $this->createForm(CatalogTopicFormType::class, $dto);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }
            if ($form->isValid()) {
                try {
                    $this->writer->updateTopic($topic->getId(), $dto->name, $dto->summary, (int) $dto->position, $dto->estimatedMinutes);
                    $this->addFlash('success', 'Konu güncellendi.');

                    return $this->redirectToRoute('app_admin_catalog_unit', ['id' => $topic->getUnit()->getId()->toRfc4122()]);
                } catch (CatalogException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        return $this->renderAdmin('admin/catalog/topic_form.html.twig', [
            'form' => $form,
            'unit' => $topic->getUnit(),
            'topic' => $topic,
            'title' => 'Konuyu düzenle',
        ]);
    }

    #[Route('/yonetim/mufredat/konu/{id}/yayimla', name: 'app_admin_catalog_topic_publish', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MANAGE)]
    public function topicPublish(Request $request, string $id): Response
    {
        $topic = $this->requireTopic($id);
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('catalog_publish_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }
        try {
            $this->writer->publishTopic($topic->getId());
            $this->addFlash('success', 'Konu yayımlandı.');
        } catch (CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_catalog_unit', ['id' => $topic->getUnit()->getId()->toRfc4122()]);
    }

    #[Route('/yonetim/mufredat/konu/{id}/arsivle', name: 'app_admin_catalog_topic_archive', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_MANAGE)]
    public function topicArchive(Request $request, string $id): Response
    {
        $topic = $this->requireTopic($id);
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('catalog_archive_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }
        try {
            $this->writer->archiveTopic($topic->getId());
            $this->addFlash('success', 'Konu arşivlendi.');
        } catch (CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_catalog_unit', ['id' => $topic->getUnit()->getId()->toRfc4122()]);
    }

    #[Route('/yonetim/mufredat/konu/{id}', name: 'app_admin_catalog_topic', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_VIEW)]
    public function topicShow(string $id): Response
    {
        $topic = $this->requireTopic($id);
        $canonical = $topic->getUnit()->getSubject()->getCanonicalSubject();
        $publishedContents = null !== $canonical
            ? $this->learningContents->findPublishedPlatformBySubject($canonical)
            : [];

        return $this->renderAdmin('admin/catalog/topic_show.html.twig', [
            'topic' => $topic,
            'unit' => $topic->getUnit(),
            'subject' => $topic->getUnit()->getSubject(),
            'placements' => $this->placementRepo->findOrderedByTopic($topic),
            'published_contents' => $publishedContents,
            'suggested_position' => PlacementPosition::next($this->placementRepo->highestPositionForTopic($topic->getId())),
            'reason_placement_create' => ContentWorkflowReason::PLACEMENT_CREATE,
            'reason_placement_publish' => ContentWorkflowReason::PLACEMENT_PUBLISH,
            'reason_placement_archive' => ContentWorkflowReason::PLACEMENT_ARCHIVE,
            'can_manage' => $this->isGranted(AdminPermission::ADMIN_CATALOG_MANAGE),
            'can_create_placement' => $this->isGranted(CatalogTopicLessonPermission::CREATE)
                && CatalogPublicationStatus::Archived !== $topic->getStatus(),
        ]);
    }

    #[Route('/yonetim/mufredat/konu/{id}/yerlesim', name: 'app_admin_catalog_topic_placement_create', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_VIEW)]
    public function topicPlacementCreate(Request $request, string $id): Response
    {
        $topic = $this->requireTopic($id);
        $this->denyAccessUnlessGranted(CatalogTopicLessonPermission::CREATE);
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('catalog_topic_placement_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }

        $contentIdRaw = (string) $request->request->get('learning_content_id', '');
        $displayTitle = trim((string) $request->request->get('display_title', ''));
        $slug = trim((string) $request->request->get('slug', ''));
        $summaryRaw = trim((string) $request->request->get('summary', ''));

        try {
            $contentUuid = Uuid::fromString($contentIdRaw);
            if ('' === $displayTitle || '' === $summaryRaw) {
                $linked = $this->learningContents->findOneById($contentUuid);
                if ($linked instanceof LearningContent) {
                    if ('' === $displayTitle) {
                        $displayTitle = $linked->getTitle();
                    }
                    if ('' === $summaryRaw && null !== $linked->getSummary()) {
                        $summaryRaw = $linked->getSummary();
                    }
                }
            }
            $positionRaw = trim((string) $request->request->get('position', ''));
            $position = '' === $positionRaw
                ? PlacementPosition::next($this->placementRepo->highestPositionForTopic($topic->getId()))
                : (int) $positionRaw;
            $reason = $this->workflowReason->resolve(
                ContentWorkflowReason::PLACEMENT_CREATE,
                $request->request->get('note'),
                $request->request->get('operator_note'),
            );
            $this->placements->create(
                $this->requireActorUser(),
                $topic->getId(),
                $contentUuid,
                $displayTitle,
                '' === $summaryRaw ? null : $summaryRaw,
                $position,
                $reason['code'],
                '' === $slug ? null : $slug,
                $reason['operator_note'],
            );
            $this->addFlash('success', 'Yerleşim taslağı oluşturuldu.');
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'Geçersiz öğrenme içeriği.');
        } catch (LearningContentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_catalog_topic', ['id' => $id]);
    }

    #[Route('/yonetim/mufredat/yerlesim/{id}/yayimla', name: 'app_admin_catalog_placement_publish', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_VIEW)]
    public function placementPublish(Request $request, string $id): Response
    {
        $lesson = $this->requirePlacement($id);
        $this->denyAccessUnlessGranted(CatalogTopicLessonPermission::PUBLISH, $lesson);
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('catalog_placement_publish_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }
        if ('1' !== (string) $request->request->get('confirm_publish')) {
            $this->addFlash('error', 'Yayımlama için onay kutusu zorunludur.');

            return $this->redirectToRoute('app_admin_catalog_topic', [
                'id' => $lesson->getCatalogTopic()->getId()->toRfc4122(),
            ]);
        }

        try {
            $reason = $this->workflowReason->resolve(
                ContentWorkflowReason::PLACEMENT_PUBLISH,
                $request->request->get('note'),
                $request->request->get('operator_note'),
            );
            $this->placements->publish(
                $this->requireActorUser(),
                $lesson->getId(),
                $reason['code'],
                $reason['operator_note'],
            );
            $this->addFlash('success', 'Yerleşim yayımlandı.');
        } catch (LearningContentException|CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_catalog_topic', [
            'id' => $lesson->getCatalogTopic()->getId()->toRfc4122(),
        ]);
    }

    #[Route('/yonetim/mufredat/yerlesim/{id}/arsivle', name: 'app_admin_catalog_placement_archive', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CATALOG_VIEW)]
    public function placementArchive(Request $request, string $id): Response
    {
        $lesson = $this->requirePlacement($id);
        $this->denyAccessUnlessGranted(CatalogTopicLessonPermission::ARCHIVE, $lesson);
        $this->requireCsrfTokenPresent($request->request->all());
        if (!$this->isCsrfTokenValid('catalog_placement_archive_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
        }

        try {
            $reason = $this->workflowReason->resolve(
                ContentWorkflowReason::PLACEMENT_ARCHIVE,
                $request->request->get('note'),
                $request->request->get('operator_note'),
            );
            $this->placements->archive(
                $this->requireActorUser(),
                $lesson->getId(),
                $reason['code'],
                $reason['operator_note'],
            );
            $this->addFlash('success', 'Yerleşim arşivlendi (geri açılamaz).');
        } catch (LearningContentException|CatalogException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_catalog_topic', [
            'id' => $lesson->getCatalogTopic()->getId()->toRfc4122(),
        ]);
    }

    private function requireSubject(string $id): CatalogSubject
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        $subject = $this->subjects->findOneById($uuid);
        if (!$subject instanceof CatalogSubject) {
            throw $this->createNotFoundException();
        }

        return $subject;
    }

    private function requireUnit(string $id): CatalogUnit
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        $unit = $this->units->findOneById($uuid);
        if (!$unit instanceof CatalogUnit) {
            throw $this->createNotFoundException();
        }

        return $unit;
    }

    private function requireTopic(string $id): CatalogTopic
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        $topic = $this->topics->findOneById($uuid);
        if (!$topic instanceof CatalogTopic) {
            throw $this->createNotFoundException();
        }

        return $topic;
    }

    private function requirePlacement(string $id): CatalogTopicLesson
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        $lesson = $this->placementRepo->findOneById($uuid);
        if (!$lesson instanceof CatalogTopicLesson) {
            throw $this->createNotFoundException();
        }

        return $lesson;
    }
}

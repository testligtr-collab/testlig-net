<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\AdminClassroomArchiveRequest;
use App\Dto\AdminClassroomCreateRequest;
use App\Dto\AdminClassroomDetailView;
use App\Dto\AdminClassroomReviseRequest;
use App\Entity\AcademicYear;
use App\Entity\Classroom;
use App\Entity\Institution;
use App\Enum\ClassroomFailureReason;
use App\Enum\CommerceFailureReason;
use App\Enum\GradeLevel;
use App\Exception\ClassroomException;
use App\Exception\CommerceException;
use App\Form\AdminClassroomArchiveFormType;
use App\Form\AdminClassroomCreateFormType;
use App\Form\AdminClassroomReviseFormType;
use App\Repository\AcademicYearRepository;
use App\Repository\ClassroomRepository;
use App\Repository\InstitutionRepository;
use App\Security\AdminPermission;
use App\Service\Admin\AdminClassroomQuery;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use App\Service\ClassroomManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminClassroomController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminClassroomQuery $classroomQuery,
        private readonly ClassroomRepository $classrooms,
        private readonly AcademicYearRepository $years,
        private readonly InstitutionRepository $institutions,
        private readonly ClassroomManager $classroomManager,
        #[Autowire(service: 'limiter.admin_classroom_create')]
        private readonly RateLimiterFactory $createLimiter,
        #[Autowire(service: 'limiter.admin_classroom_revise')]
        private readonly RateLimiterFactory $reviseLimiter,
        #[Autowire(service: 'limiter.admin_classroom_archive')]
        private readonly RateLimiterFactory $archiveLimiter,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/siniflar', name: 'app_admin_classrooms', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_CLASSROOMS_VIEW)]
    public function list(Request $request): Response
    {
        $filters = [
            'q' => $request->query->getString('q'),
            'institution' => $request->query->getString('institution'),
            'year' => $request->query->getString('year'),
            'status' => $request->query->getString('status'),
            'page' => $request->query->getInt('page', 1),
            'page_size' => $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE),
        ];
        $actorId = $this->requireActorId();

        try {
            $result = $this->classroomQuery->listClassrooms($actorId, $filters);
            $summary = $this->classroomQuery->summarize($actorId);
            $institutions = $this->classroomQuery->institutionChoices($actorId);
            $years = $this->classroomQuery->yearChoices($actorId);
            $canCreate = $this->isGranted(AdminPermission::ADMIN_CLASSROOMS_CREATE);
        } catch (CommerceException $e) {
            if (CommerceFailureReason::InvalidInput === $e->getReason()) {
                throw $this->createNotFoundException('Geçersiz filtre.', $e);
            }
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/classrooms/list.html.twig', [
            'result' => $result,
            'summary' => $summary,
            'institutions' => $institutions,
            'years' => $years,
            'filters' => $filters,
            'can_create' => $canCreate,
        ]);
    }

    #[Route('/yonetim/siniflar/yeni', name: 'app_admin_classroom_new', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_CLASSROOMS_CREATE)]
    public function new(Request $request): Response
    {
        $actorId = $this->requireActorId();
        $institutions = $this->createInstitutions($actorId);
        $years = $this->createYears($actorId);
        $dto = new AdminClassroomCreateRequest();
        $institutionId = $request->query->getString('kurum');
        $yearId = $request->query->getString('yil');
        foreach ($institutions as $institution) {
            if ($institution->id === $institutionId) {
                $dto->institutionId = $institutionId;
            }
        }
        foreach ($years as $year) {
            if ($year->id === $yearId && $year->institutionId === $dto->institutionId) {
                $dto->academicYearId = $yearId;
            }
        }
        $form = $this->createForm(AdminClassroomCreateFormType::class, $dto, [
            'institutions' => $institutions,
            'years' => $years,
            'action' => $this->generateUrl('app_admin_classroom_create'),
            'method' => 'POST',
        ]);

        return $this->renderNew($form);
    }

    #[Route('/yonetim/siniflar', name: 'app_admin_classroom_create', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_CLASSROOMS_CREATE)]
    public function create(Request $request): Response
    {
        $actorId = $this->requireActorId();
        $payload = $request->request->all()['admin_classroom_create'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);
        $institutions = $this->createInstitutions($actorId);
        $years = $this->createYears($actorId);
        $dto = new AdminClassroomCreateRequest();
        $form = $this->createForm(AdminClassroomCreateFormType::class, $dto, [
            'institutions' => $institutions,
            'years' => $years,
        ]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }

            return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $institution = $this->institutions->findOneById(Uuid::fromString($dto->institutionId));
        $year = $this->years->findOneById(Uuid::fromString($dto->academicYearId));
        if (!$institution instanceof Institution) {
            $form->get('institutionId')->addError(new FormError('Kurum bulunamadı.'));

            return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$year instanceof AcademicYear || !$year->getInstitution()->getId()->equals($institution->getId())) {
            $form->get('academicYearId')->addError(new FormError('Akademik yıl seçilen kuruma ait değil.'));

            return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $grade = GradeLevel::tryFrom((int) $dto->gradeLevel);
        if (!$grade instanceof GradeLevel) {
            $form->get('gradeLevel')->addError(new FormError('Müfredat sınıf düzeyi geçersiz.'));

            return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->consume($this->createLimiter, $actorId->toRfc4122().':create');

        try {
            $classroom = $this->classroomManager->create(
                $year,
                $this->requireActorUser(),
                $dto->name,
                $grade,
                $dto->reasonCode,
                $this->optionalText($dto->sectionCode),
                $this->optionalCapacity($dto->capacity),
            );
        } catch (ClassroomException $e) {
            return $this->renderCreateFailure($form, $e);
        }

        $this->addFlash('success', 'Sınıf oluşturuldu.');
        $response = $this->redirectToRoute('app_admin_classroom_detail', ['id' => $classroom->getId()]);
        $this->applyNoStore($response);

        return $response;
    }

    #[Route('/yonetim/siniflar/{id}', name: 'app_admin_classroom_detail', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CLASSROOMS_VIEW)]
    public function detail(Uuid $id): Response
    {
        $classroom = $this->requireDetail($id);

        return $this->renderDetail($classroom, $this->reviseForm($classroom), $this->archiveForm($classroom));
    }

    #[Route('/yonetim/siniflar/{id}/guncelle', name: 'app_admin_classroom_revise', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CLASSROOMS_MANAGE)]
    public function revise(Request $request, Uuid $id): Response
    {
        $actorId = $this->requireActorId();
        $payload = $request->request->all()['admin_classroom_revise'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);
        $classroom = $this->requireDetail($id);
        $entity = $this->requireEntity($id, $classroom);
        $dto = new AdminClassroomReviseRequest();
        $form = $this->createForm(AdminClassroomReviseFormType::class, $dto, ['classroom_id' => $id]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }

            return $this->renderDetail($classroom, $form->createView(), $this->archiveForm($classroom), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$classroom->canRevise) {
            $this->addFlash('error', 'Bu sınıf şu anki durumunda düzenlenemez.');

            return $this->redirectToRoute('app_admin_classroom_detail', ['id' => $id]);
        }

        $this->consume($this->reviseLimiter, $actorId->toRfc4122().':'.$id->toRfc4122().':revise');

        try {
            $this->classroomManager->revise(
                $entity,
                $this->requireActorUser(),
                $dto->name,
                $this->optionalCapacity($dto->capacity),
                (int) $dto->expectedUpdatedAt,
                $dto->reasonCode,
            );
        } catch (ClassroomException $e) {
            if (ClassroomFailureReason::Unauthorized === $e->getReason()) {
                throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.');
            }
            $form->addError(new FormError(self::reviseMessage($e)));

            return $this->renderDetail($classroom, $form->createView(), $this->archiveForm($classroom), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'Sınıf güncellendi.');
        $response = $this->redirectToRoute('app_admin_classroom_detail', ['id' => $id]);
        $this->applyNoStore($response);

        return $response;
    }

    #[Route('/yonetim/siniflar/{id}/arsivle', name: 'app_admin_classroom_archive', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_CLASSROOMS_MANAGE)]
    public function archive(Request $request, Uuid $id): Response
    {
        $actorId = $this->requireActorId();
        $payload = $request->request->all()['admin_classroom_archive'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);
        $classroom = $this->requireDetail($id);
        $entity = $this->requireEntity($id, $classroom);
        $dto = new AdminClassroomArchiveRequest();
        $form = $this->createForm(AdminClassroomArchiveFormType::class, $dto, ['classroom_id' => $id]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }

            return $this->renderDetail($classroom, $this->reviseForm($classroom), $form->createView(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$classroom->canArchive) {
            $this->addFlash('error', 'Bu sınıf şu anki durumunda arşivlenemez.');

            return $this->redirectToRoute('app_admin_classroom_detail', ['id' => $id]);
        }

        $this->consume($this->archiveLimiter, $actorId->toRfc4122().':'.$id->toRfc4122().':archive');

        try {
            $this->classroomManager->archive($entity, $this->requireActorUser(), $dto->reasonCode);
        } catch (ClassroomException $e) {
            if (ClassroomFailureReason::Unauthorized === $e->getReason()) {
                throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.');
            }
            $this->addFlash('error', self::archiveMessage($e));
            $response = $this->redirectToRoute('app_admin_classroom_detail', ['id' => $id]);
            $this->applyNoStore($response);

            return $response;
        }

        $this->addFlash('success', 'Sınıf arşivlendi.');
        $response = $this->redirectToRoute('app_admin_classroom_detail', ['id' => $id]);
        $this->applyNoStore($response);

        return $response;
    }

    /**
     * @return list<\App\Dto\AdminClassroomInstitutionChoice>
     */
    private function createInstitutions(Uuid $actorId): array
    {
        try {
            return $this->classroomQuery->institutionChoices($actorId);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }
    }

    /**
     * @return list<\App\Dto\AdminClassroomYearChoice>
     */
    private function createYears(Uuid $actorId): array
    {
        try {
            return $this->classroomQuery->yearChoices($actorId, true);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }
    }

    private function requireDetail(Uuid $id): AdminClassroomDetailView
    {
        try {
            return $this->classroomQuery->getDetail($this->requireActorId(), $id);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }
    }

    private function requireEntity(Uuid $id, AdminClassroomDetailView $classroom): Classroom
    {
        $entity = $this->classrooms->findOneById($id);
        if (!$entity instanceof Classroom
            || !$entity->getInstitution()->getId()->equals(Uuid::fromString($classroom->institutionId))
            || !$entity->getAcademicYear()->getId()->equals(Uuid::fromString($classroom->academicYearId))) {
            throw $this->createNotFoundException('Kayıt bulunamadı.');
        }

        return $entity;
    }

    private function reviseForm(AdminClassroomDetailView $classroom): ?FormView
    {
        if (!$classroom->canRevise) {
            return null;
        }
        $dto = new AdminClassroomReviseRequest();
        $dto->name = $classroom->name;
        $dto->capacity = null === $classroom->capacity ? '' : (string) $classroom->capacity;
        $dto->expectedUpdatedAt = (string) $classroom->updatedAt;

        return $this->createForm(AdminClassroomReviseFormType::class, $dto, [
            'classroom_id' => Uuid::fromString($classroom->id),
            'action' => $this->generateUrl('app_admin_classroom_revise', ['id' => $classroom->id]),
            'method' => 'POST',
        ])->createView();
    }

    private function archiveForm(AdminClassroomDetailView $classroom): ?FormView
    {
        if (!$classroom->canArchive) {
            return null;
        }

        return $this->createForm(AdminClassroomArchiveFormType::class, new AdminClassroomArchiveRequest(), [
            'classroom_id' => Uuid::fromString($classroom->id),
            'action' => $this->generateUrl('app_admin_classroom_archive', ['id' => $classroom->id]),
            'method' => 'POST',
        ])->createView();
    }

    /**
     * @param FormInterface<AdminClassroomCreateRequest> $form
     */
    private function renderNew(FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->renderAdmin('admin/classrooms/new.html.twig', [
            'form' => $form->createView(),
        ], new Response(status: $status));
    }

    private function renderDetail(
        AdminClassroomDetailView $classroom,
        ?FormView $reviseForm,
        ?FormView $archiveForm,
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->renderAdmin('admin/classrooms/detail.html.twig', [
            'classroom' => $classroom,
            'revise_form' => $reviseForm,
            'archive_form' => $archiveForm,
        ], new Response(status: $status));
    }

    /**
     * @param FormInterface<AdminClassroomCreateRequest> $form
     */
    private function renderCreateFailure(FormInterface $form, ClassroomException $e): Response
    {
        if (ClassroomFailureReason::Unauthorized === $e->getReason()) {
            throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.');
        }
        if (ClassroomFailureReason::NotFound === $e->getReason()) {
            $form->get('institutionId')->addError(new FormError('Kurum bulunamadı.'));
        } elseif (ClassroomFailureReason::InstitutionNotOperable === $e->getReason()) {
            $form->get('institutionId')->addError(new FormError('Kurum bu durumda sınıf oluşturulamaz.'));
        } elseif (ClassroomFailureReason::YearNotOperable === $e->getReason()) {
            $form->get('academicYearId')->addError(new FormError('Kapalı akademik yıla sınıf eklenemez.'));
        } elseif (ClassroomFailureReason::Conflict === $e->getReason()) {
            $form->get('name')->addError(new FormError('Bu ad aynı akademik yılda kullanılıyor.'));
        } elseif (ClassroomFailureReason::CrossInstitution === $e->getReason()) {
            $form->get('academicYearId')->addError(new FormError('Akademik yıl seçilen kuruma ait değil.'));
        } elseif (ClassroomFailureReason::InvalidInput === $e->getReason()) {
            $form->get('name')->addError(new FormError('Sınıf bilgisi geçersiz.'));
        } else {
            $form->addError(new FormError('İşlem tamamlanamadı.'));
        }

        return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private static function reviseMessage(ClassroomException $e): string
    {
        return match ($e->getReason()) {
            ClassroomFailureReason::ClassroomNotOperable => 'Arşivdeki sınıf düzenlenemez.',
            ClassroomFailureReason::YearNotOperable => 'Kapalı akademik yıldaki sınıf düzenlenemez.',
            ClassroomFailureReason::InstitutionNotOperable => 'Kurum bu durumda sınıf işlemine izin vermiyor.',
            ClassroomFailureReason::CapacityBelowEnrollment => 'Kontenjan aktif öğrenci sayısından küçük olamaz.',
            ClassroomFailureReason::Conflict => 'Kayıt başka bir işlemle değişti veya bu ad aynı yılda kullanılıyor.',
            ClassroomFailureReason::InvalidInput => 'Sınıf bilgisi geçersiz.',
            default => 'Güncelleme tamamlanamadı.',
        };
    }

    private static function archiveMessage(ClassroomException $e): string
    {
        return match ($e->getReason()) {
            ClassroomFailureReason::InstitutionNotOperable => 'Kurum bu durumda sınıf arşivlenemez.',
            ClassroomFailureReason::InvalidTransition, ClassroomFailureReason::ClassroomNotOperable => 'Bu sınıf arşivlenemez.',
            ClassroomFailureReason::Conflict => 'İşlem çakıştı. Güncel durumu kontrol edin.',
            default => 'Arşivleme tamamlanamadı.',
        };
    }

    private static function optionalText(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private static function optionalCapacity(string $value): ?int
    {
        $value = trim($value);

        return '' === $value ? null : (int) $value;
    }

    private function consume(RateLimiterFactory $factory, string $key): void
    {
        $rateLimit = $factory->create($key)->consume(1);
        if (!$rateLimit->isAccepted()) {
            $seconds = max(1, $rateLimit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($seconds, 'Çok fazla istek. Lütfen daha sonra tekrar deneyin.');
        }
    }
}

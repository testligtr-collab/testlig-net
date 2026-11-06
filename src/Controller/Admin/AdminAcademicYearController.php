<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\AdminAcademicYearActivateRequest;
use App\Dto\AdminAcademicYearCreateRequest;
use App\Dto\AdminAcademicYearDetailView;
use App\Entity\AcademicYear;
use App\Entity\Institution;
use App\Enum\AcademicYearFailureReason;
use App\Enum\CommerceFailureReason;
use App\Exception\AcademicYearException;
use App\Exception\CommerceException;
use App\Form\AdminAcademicYearActivateFormType;
use App\Form\AdminAcademicYearCreateFormType;
use App\Repository\AcademicYearRepository;
use App\Repository\InstitutionRepository;
use App\Security\AdminPermission;
use App\Service\AcademicYearManager;
use App\Service\Admin\AdminAcademicYearQuery;
use App\Service\Admin\AdminNavBuilder;
use App\Service\Admin\AdminPagination;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminAcademicYearController extends AdminBaseController
{
    public function __construct(
        AdminNavBuilder $adminNavBuilder,
        private readonly AdminAcademicYearQuery $yearQuery,
        private readonly AcademicYearRepository $years,
        private readonly InstitutionRepository $institutions,
        private readonly AcademicYearManager $academicYearManager,
        #[Autowire(service: 'limiter.admin_academic_year_create')]
        private readonly RateLimiterFactory $createLimiter,
        #[Autowire(service: 'limiter.admin_academic_year_activate')]
        private readonly RateLimiterFactory $activateLimiter,
    ) {
        parent::__construct($adminNavBuilder);
    }

    #[Route('/yonetim/akademik-yillar', name: 'app_admin_academic_years', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_ACADEMIC_YEARS_VIEW)]
    public function list(Request $request): Response
    {
        $filters = [
            'q' => $request->query->getString('q'),
            'institution' => $request->query->getString('institution'),
            'status' => $request->query->getString('status'),
            'page' => $request->query->getInt('page', 1),
            'page_size' => $request->query->getInt('page_size', AdminPagination::DEFAULT_PAGE_SIZE),
        ];
        $actorId = $this->requireActorId();

        try {
            $result = $this->yearQuery->listYears($actorId, $filters);
            $summary = $this->yearQuery->summarize($actorId);
            $institutions = $this->yearQuery->institutionChoices($actorId);
        } catch (CommerceException $e) {
            if (CommerceFailureReason::InvalidInput === $e->getReason()) {
                throw $this->createNotFoundException('Geçersiz filtre.', $e);
            }
            $this->mapCommerceException($e);
        }

        return $this->renderAdmin('admin/academic_years/list.html.twig', [
            'result' => $result,
            'summary' => $summary,
            'filters' => $filters,
            'institutions' => $institutions,
            'can_create' => $this->isGranted(AdminPermission::ADMIN_ACADEMIC_YEARS_CREATE),
        ]);
    }

    #[Route('/yonetim/akademik-yillar/yeni', name: 'app_admin_academic_year_new', methods: ['GET'])]
    #[IsGranted(AdminPermission::ADMIN_ACADEMIC_YEARS_CREATE)]
    public function newForm(#[MapQueryParameter] string $kurum = ''): Response
    {
        $dto = new AdminAcademicYearCreateRequest();
        if (Uuid::isValid($kurum)) {
            $dto->institutionId = $kurum;
        }
        $form = $this->createForm(AdminAcademicYearCreateFormType::class, $dto, [
            'institutions' => $this->institutionChoices(),
            'action' => $this->generateUrl('app_admin_academic_year_create'),
            'method' => 'POST',
        ]);

        return $this->renderNew($form);
    }

    #[Route('/yonetim/akademik-yillar', name: 'app_admin_academic_year_create', methods: ['POST'])]
    #[IsGranted(AdminPermission::ADMIN_ACADEMIC_YEARS_CREATE)]
    public function create(Request $request): Response
    {
        $actorId = $this->requireActorId();
        $payload = $request->request->all()['admin_academic_year_create'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);

        $dto = new AdminAcademicYearCreateRequest();
        $form = $this->createForm(AdminAcademicYearCreateFormType::class, $dto, [
            'institutions' => $this->institutionChoices(),
        ]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }

            return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $institution = $this->institutions->findOneById(Uuid::fromString($dto->institutionId));
        if (!$institution instanceof Institution) {
            $form->get('institutionId')->addError(new FormError('Kurum bulunamadı.'));

            return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $starts = self::calendarDate($dto->startsOn);
        $ends = self::calendarDate($dto->endsOn);
        if (!$starts instanceof \DateTimeImmutable || !$ends instanceof \DateTimeImmutable || $starts > $ends) {
            $form->get('endsOn')->addError(new FormError('Bitiş tarihi başlangıçtan önce olamaz.'));

            return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->consume($this->createLimiter, $actorId->toRfc4122().':create');

        try {
            $year = $this->academicYearManager->createPlanned(
                $institution,
                $this->requireActorUser(),
                $dto->name,
                $starts,
                $ends,
                $dto->reasonCode,
            );
        } catch (AcademicYearException $e) {
            return $this->renderCreateFailure($form, $e);
        }

        $this->addFlash('success', 'Akademik yıl oluşturuldu.');
        $response = $this->redirectToRoute('app_admin_academic_year_detail', ['id' => $year->getId()]);
        $this->applyNoStore($response);

        return $response;
    }

    #[Route('/yonetim/akademik-yillar/{id}', name: 'app_admin_academic_year_detail', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_ACADEMIC_YEARS_VIEW)]
    public function detail(Uuid $id): Response
    {
        $year = $this->requireDetail($id);

        return $this->renderDetail($year, $year->canActivate ? $this->activateForm($year) : null);
    }

    #[Route('/yonetim/akademik-yillar/{id}/aktiflestir', name: 'app_admin_academic_year_activate', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted(AdminPermission::ADMIN_ACADEMIC_YEARS_MANAGE)]
    public function activate(Request $request, Uuid $id): Response
    {
        $actorId = $this->requireActorId();
        $payload = $request->request->all()['admin_academic_year_activate'] ?? null;
        $this->requireCsrfTokenPresent(\is_array($payload) ? $payload : null);
        $year = $this->requireDetail($id);
        $entity = $this->years->findOneById($id);
        if (!$entity instanceof AcademicYear || !$entity->getInstitution()->getId()->equals(Uuid::fromString($year->institutionId))) {
            throw $this->createNotFoundException('Kayıt bulunamadı.');
        }

        $dto = new AdminAcademicYearActivateRequest();
        $form = $this->createForm(AdminAcademicYearActivateFormType::class, $dto, ['year_id' => $id]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($this->formHasCsrfFailure($form)) {
                throw $this->createAccessDeniedException('CSRF doğrulaması başarısız.');
            }

            return $this->renderDetail($year, $form->createView(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$year->canActivate) {
            $this->addFlash('error', 'Bu yıl şu anki durumunda aktifleştirilemez.');

            return $this->redirectToRoute('app_admin_academic_year_detail', ['id' => $id]);
        }

        $this->consume($this->activateLimiter, $actorId->toRfc4122().':'.$id->toRfc4122().':activate');

        try {
            $this->academicYearManager->activate($entity, $this->requireActorUser(), $dto->reasonCode);
        } catch (AcademicYearException $e) {
            if (AcademicYearFailureReason::Unauthorized === $e->getReason()) {
                throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.');
            }
            $this->addFlash('error', self::activationMessage($e));
            $response = $this->redirectToRoute('app_admin_academic_year_detail', ['id' => $id]);
            $this->applyNoStore($response);

            return $response;
        }

        $this->addFlash('success', 'Akademik yıl aktifleştirildi.');
        $response = $this->redirectToRoute('app_admin_academic_year_detail', ['id' => $id]);
        $this->applyNoStore($response);

        return $response;
    }

    /**
     * @return list<\App\Dto\AdminAcademicYearInstitutionChoice>
     */
    private function institutionChoices(): array
    {
        try {
            return $this->yearQuery->institutionChoices($this->requireActorId());
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }
    }

    private function requireDetail(Uuid $id): AdminAcademicYearDetailView
    {
        try {
            return $this->yearQuery->getDetail($this->requireActorId(), $id);
        } catch (CommerceException $e) {
            $this->mapCommerceException($e);
        }
    }

    private function activateForm(AdminAcademicYearDetailView $year): FormView
    {
        return $this->createForm(AdminAcademicYearActivateFormType::class, new AdminAcademicYearActivateRequest(), [
            'year_id' => $year->id,
            'action' => $this->generateUrl('app_admin_academic_year_activate', ['id' => $year->id]),
            'method' => 'POST',
        ])->createView();
    }

    /**
     * @param FormInterface<AdminAcademicYearCreateRequest> $form
     */
    private function renderNew(FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->renderAdmin('admin/academic_years/new.html.twig', [
            'form' => $form->createView(),
        ], new Response(status: $status));
    }

    private function renderDetail(AdminAcademicYearDetailView $year, ?FormView $activateForm, int $status = Response::HTTP_OK): Response
    {
        return $this->renderAdmin('admin/academic_years/detail.html.twig', [
            'year' => $year,
            'activate_form' => $activateForm,
        ], new Response(status: $status));
    }

    /**
     * @param FormInterface<AdminAcademicYearCreateRequest> $form
     */
    private function renderCreateFailure(FormInterface $form, AcademicYearException $e): Response
    {
        if (AcademicYearFailureReason::Unauthorized === $e->getReason()) {
            throw $this->createAccessDeniedException('Bu işlem için yetkiniz yok.');
        }
        if (AcademicYearFailureReason::NotFound === $e->getReason()) {
            $form->get('institutionId')->addError(new FormError('Kurum bulunamadı.'));
        } elseif (AcademicYearFailureReason::InstitutionNotOperable === $e->getReason()) {
            $form->get('institutionId')->addError(new FormError('Kurum bu durumda akademik yıl işlemine izin vermiyor.'));
        } elseif (AcademicYearFailureReason::Conflict === $e->getReason()) {
            $form->get('name')->addError(new FormError('Bu yıl adı bu kurumda zaten kullanılıyor.'));
        } elseif (AcademicYearFailureReason::DateOverlap === $e->getReason()) {
            $form->get('endsOn')->addError(new FormError('Bu tarih aralığı aynı kurumdaki başka bir yılla çakışıyor.'));
        } elseif (AcademicYearFailureReason::InvalidInput === $e->getReason()) {
            $form->get('name')->addError(new FormError('Yıl adı geçersiz.'));
        } else {
            $form->addError(new FormError('İşlem tamamlanamadı.'));
        }

        return $this->renderNew($form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private static function activationMessage(AcademicYearException $e): string
    {
        return match ($e->getReason()) {
            AcademicYearFailureReason::InstitutionNotOperable => 'Aktif olmayan kurumda akademik yıl aktifleştirilemez.',
            AcademicYearFailureReason::InvalidTransition => 'Bu yıl aktifleştirilemez.',
            AcademicYearFailureReason::Conflict => 'İşlem çakıştı. Güncel durumu kontrol edin.',
            AcademicYearFailureReason::NotFound => 'Akademik yıl bulunamadı.',
            default => 'Aktifleştirme tamamlanamadı.',
        };
    }

    private static function calendarDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
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

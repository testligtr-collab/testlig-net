<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\InstitutionWorkspaceDecision;
use App\Entity\Institution;
use App\Entity\User;
use App\Enum\ClassroomFailureReason;
use App\Enum\ClassroomStatus;
use App\Enum\GradeLevel;
use App\Exception\ClassroomException;
use App\Service\InstitutionClassroomEditor;
use App\Service\InstitutionWorkspaceGate;
use App\Service\InstitutionWorkspaceQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/kurum')]
#[IsGranted('ROLE_USER')]
final class InstitutionWorkspaceController extends AbstractController
{
    public function __construct(
        private readonly InstitutionWorkspaceGate $gate,
        private readonly InstitutionWorkspaceQuery $query,
        private readonly InstitutionClassroomEditor $classrooms,
    ) {
    }

    #[Route('', name: 'app_institution_overview', methods: ['GET'])]
    public function overview(): Response
    {
        $user = $this->account();
        $decision = $this->gate->resolve($user);
        $blocked = $this->blocked($user, $decision);
        if ($blocked instanceof Response) {
            return $blocked;
        }
        $selected = $decision->selected;
        if (null === $selected) {
            return $this->render('institution/choose.html.twig', ['options' => $decision->options]);
        }

        return $this->render('institution/overview.html.twig', $this->frame($decision, 'overview', [
            'overview' => $this->query->overview($selected, \count($decision->options) > 1),
        ]));
    }

    #[Route('/siniflar', name: 'app_institution_classrooms', methods: ['GET'])]
    public function classrooms(Request $request): Response
    {
        $opened = $this->open();
        if ($opened instanceof Response) {
            return $opened;
        }
        $selected = $opened->selected;
        if (null === $selected) {
            throw new AccessDeniedHttpException('Kurum seçilmeden bu liste açılamaz.');
        }

        $status = 'arsiv' === (string) $request->query->get('durum') ? ClassroomStatus::Archived : ClassroomStatus::Active;

        return $this->render('institution/classrooms.html.twig', $this->frame($opened, 'classrooms', [
            'classrooms' => $this->query->classrooms($selected->getInstitution(), $this->pageNumber($request), $status),
            'archive_view' => ClassroomStatus::Archived === $status,
        ]));
    }

    #[Route('/siniflar/{reference}', name: 'app_institution_classroom', methods: ['GET'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function classroom(string $reference): Response
    {
        $institution = $this->institution();
        $detail = $this->query->classroom($institution, strtolower($reference));
        if (null === $detail) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->render('institution/classroom.html.twig', $this->frame($this->gate->resolve($this->account()), 'classrooms', [
            'detail' => $detail,
        ]));
    }

    #[Route('/siniflar/yeni', name: 'app_institution_classroom_new', methods: ['GET', 'POST'])]
    public function newClassroom(Request $request): Response
    {
        $opened = $this->open();
        if ($opened instanceof Response) {
            return $opened;
        }
        $selected = $opened->selected;
        if (null === $selected) {
            throw new AccessDeniedHttpException('Kurum seçilmeden bu liste açılamaz.');
        }
        $institution = $selected->getInstitution();
        $years = $this->classrooms->operableYears($institution);
        $values = [
            'name' => '',
            'grade_level' => '',
            'section_code' => '',
            'capacity' => '',
            'year_reference' => '',
        ];
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('institution_classroom', (string) $request->request->get('_token'))) {
                throw new AccessDeniedHttpException('Geçersiz istek.');
            }
            $values = $this->classroomInput($request);
            $grade = $this->grade($values['grade_level']);
            $capacity = $this->capacity($values['capacity']);
            if (!$grade instanceof GradeLevel || false === $capacity) {
                $error = 'Sınıf adı, seviye ve kontenjan alanlarını kontrol edin.';
            } else {
                try {
                    $reference = $this->classrooms->create(
                        $this->account(),
                        $institution,
                        $values['year_reference'],
                        $values['name'],
                        $grade,
                        $values['section_code'],
                        $capacity,
                    );
                    $this->addFlash('success', 'Sınıf oluşturuldu.');

                    return $this->redirectToRoute('app_institution_classroom', ['reference' => $reference]);
                } catch (ClassroomException $exception) {
                    if (ClassroomFailureReason::NotFound === $exception->getReason()) {
                        throw new NotFoundHttpException('Not Found');
                    }
                    $error = $this->classroomError($exception);
                }
            }
        }

        return $this->render('institution/classroom_form.html.twig', $this->frame($opened, 'classrooms', [
            'mode' => 'create',
            'years' => $years,
            'grades' => $this->grades(),
            'values' => $values,
            'error' => $error,
            'classroom' => null,
        ]));
    }

    #[Route('/siniflar/{reference}/duzenle', name: 'app_institution_classroom_edit', methods: ['GET', 'POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function editClassroom(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        $detail = $this->query->classroom($institution, strtolower($reference));
        if (null === $detail) {
            throw new NotFoundHttpException('Not Found');
        }
        if (!$detail->classroom->canEdit) {
            throw new AccessDeniedHttpException('Arşivlenmiş sınıf düzenlenemez.');
        }
        $values = [
            'name' => $detail->classroom->name,
            'capacity' => $detail->classroom->capacityLabel ?? '',
            'updated_at' => $detail->classroom->updatedAtToken,
        ];
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('institution_classroom', (string) $request->request->get('_token'))) {
                throw new AccessDeniedHttpException('Geçersiz istek.');
            }
            $values['name'] = trim((string) $request->request->get('name'));
            $values['capacity'] = trim((string) $request->request->get('capacity'));
            $values['updated_at'] = trim((string) $request->request->get('updated_at'));
            $capacity = $this->capacity($values['capacity']);
            if (false === $capacity || '' === $values['name']) {
                $error = 'Sınıf adı ve kontenjan alanlarını kontrol edin.';
            } else {
                try {
                    $this->classrooms->revise(
                        $this->account(),
                        $institution,
                        strtolower($reference),
                        $values['name'],
                        $capacity,
                        $values['updated_at'],
                    );
                    $this->addFlash('success', 'Sınıf güncellendi.');

                    return $this->redirectToRoute('app_institution_classroom', ['reference' => strtolower($reference)]);
                } catch (ClassroomException $exception) {
                    if (ClassroomFailureReason::NotFound === $exception->getReason()) {
                        throw new NotFoundHttpException('Not Found');
                    }
                    $error = $this->classroomError($exception);
                }
            }
        }

        return $this->render('institution/classroom_form.html.twig', $this->frame($this->gate->resolve($this->account()), 'classrooms', [
            'mode' => 'edit',
            'years' => [],
            'grades' => [],
            'values' => $values,
            'error' => $error,
            'classroom' => $detail->classroom,
        ]));
    }

    #[Route('/siniflar/{reference}/arsivle', name: 'app_institution_classroom_archive', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function archiveClassroom(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        if (!$this->isCsrfTokenValid('institution_classroom_archive', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $reference = strtolower($reference);
        try {
            $this->classrooms->archive($this->account(), $institution, $reference);
            $this->addFlash('success', 'Sınıf arşivlendi. Bu işlem geri alınamaz.');
        } catch (ClassroomException $exception) {
            if (ClassroomFailureReason::NotFound === $exception->getReason()) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', $this->classroomError($exception));
        }

        return $this->redirectToRoute('app_institution_classroom', ['reference' => $reference]);
    }

    #[Route('/ogretmenler', name: 'app_institution_teachers', methods: ['GET'])]
    public function teachers(Request $request): Response
    {
        $institution = $this->institution();

        return $this->render('institution/people.html.twig', $this->frame($this->gate->resolve($this->account()), 'teachers', [
            'heading' => 'Öğretmenler',
            'empty_message' => 'Henüz öğretmen yok.',
            'people' => $this->query->teachers($institution, $this->pageNumber($request)),
            'show_classrooms' => true,
            'show_grade' => false,
        ]));
    }

    #[Route('/ogrenciler', name: 'app_institution_students', methods: ['GET'])]
    public function students(Request $request): Response
    {
        $institution = $this->institution();

        return $this->render('institution/people.html.twig', $this->frame($this->gate->resolve($this->account()), 'students', [
            'heading' => 'Öğrenciler',
            'empty_message' => 'Henüz öğrenci yok.',
            'people' => $this->query->students($institution, $this->pageNumber($request)),
            'show_classrooms' => false,
            'show_grade' => true,
        ]));
    }

    #[Route('/testler', name: 'app_institution_tests', methods: ['GET'])]
    public function tests(Request $request): Response
    {
        $institution = $this->institution();

        return $this->render('institution/tests.html.twig', $this->frame($this->gate->resolve($this->account()), 'tests', [
            'tests' => $this->query->tests($institution, $this->pageNumber($request)),
        ]));
    }

    #[Route('/testler/{reference}', name: 'app_institution_test', methods: ['GET'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function test(string $reference): Response
    {
        $institution = $this->institution();
        $row = $this->query->test($institution, strtolower($reference));
        if (null === $row) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->render('institution/test.html.twig', $this->frame($this->gate->resolve($this->account()), 'tests', [
            'test' => $row,
        ]));
    }

    #[Route('/baglam', name: 'app_institution_context', methods: ['POST'])]
    public function context(Request $request): Response
    {
        $user = $this->account();
        if (!$this->isCsrfTokenValid('institution_context', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $decision = $this->gate->resolve($user);
        if (InstitutionWorkspaceGate::DENIED === $decision->outcome || InstitutionWorkspaceGate::ONBOARDING === $decision->outcome) {
            throw new NotFoundHttpException('Not Found');
        }
        if (!$this->gate->select($user, (string) $request->request->get('reference'))) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->redirectToRoute('app_institution_overview');
    }

    private function blocked(User $user, InstitutionWorkspaceDecision $decision): ?Response
    {
        if (InstitutionWorkspaceGate::DENIED === $decision->outcome) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }
        if (InstitutionWorkspaceGate::ONBOARDING === $decision->outcome) {
            return $this->render('institution/onboarding.html.twig', [
                'application_message' => $this->query->applicationMessage($user),
            ]);
        }

        return null;
    }

    private function open(): InstitutionWorkspaceDecision|Response
    {
        $user = $this->account();
        $decision = $this->gate->resolve($user);
        $blocked = $this->blocked($user, $decision);
        if ($blocked instanceof Response) {
            return $blocked;
        }
        if (null === $decision->selected) {
            return $this->redirectToRoute('app_institution_overview');
        }

        return $decision;
    }

    private function institution(): Institution
    {
        $opened = $this->open();
        if ($opened instanceof Response) {
            throw new AccessDeniedHttpException('Kurum seçilmeden bu liste açılamaz.');
        }
        $selected = $opened->selected;
        if (null === $selected) {
            throw new AccessDeniedHttpException('Kurum seçilmeden bu liste açılamaz.');
        }

        return $selected->getInstitution();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function frame(InstitutionWorkspaceDecision $decision, string $nav, array $extra): array
    {
        $selected = $decision->selected;
        if (null === $selected) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }

        return $extra + [
            'nav' => $nav,
            'institution_name' => $selected->getInstitution()->getName(),
            'role_label' => InstitutionWorkspaceGate::roleLabel($selected->getRole()->value),
            'can_switch' => \count($decision->options) > 1,
            'options' => $decision->options,
        ];
    }

    private function pageNumber(Request $request): int
    {
        return max(1, min(50, (int) $request->query->get('sayfa', '1')));
    }

    /**
     * @return array{name: string, grade_level: string, section_code: string, capacity: string, year_reference: string}
     */
    private function classroomInput(Request $request): array
    {
        return [
            'name' => trim((string) $request->request->get('name')),
            'grade_level' => trim((string) $request->request->get('grade_level')),
            'section_code' => trim((string) $request->request->get('section_code')),
            'capacity' => trim((string) $request->request->get('capacity')),
            'year_reference' => strtolower(trim((string) $request->request->get('year_reference'))),
        ];
    }

    private function grade(string $value): ?GradeLevel
    {
        if (1 !== preg_match('/^\d{1,2}$/', $value)) {
            return null;
        }

        return GradeLevel::tryFrom((int) $value);
    }

    private function capacity(string $value): int|false|null
    {
        if ('' === $value) {
            return null;
        }
        if (1 !== preg_match('/^\d{1,3}$/', $value)) {
            return false;
        }

        return (int) $value;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function grades(): array
    {
        $grades = [];
        foreach (GradeLevel::cases() as $grade) {
            $grades[] = ['value' => $grade->value, 'label' => $grade->value.'. sınıf'];
        }

        return $grades;
    }

    private function classroomError(ClassroomException $exception): string
    {
        return match ($exception->getReason()) {
            ClassroomFailureReason::Conflict => 'Sınıf kaydedilemedi. Aynı dönemde bu ad kullanılıyor olabilir veya kayıt siz formu açtıktan sonra değişmiş.',
            ClassroomFailureReason::ClassroomNotOperable => 'Arşivlenmiş sınıf düzenlenemez.',
            ClassroomFailureReason::InvalidTransition => 'Bu sınıf arşivlenemez.',
            ClassroomFailureReason::YearNotOperable => 'Seçilen eğitim döneminde sınıf işlemi yapılamaz.',
            ClassroomFailureReason::InstitutionNotOperable => 'Bu kurum durumunda sınıf işlemi yapılamaz.',
            ClassroomFailureReason::CapacityBelowEnrollment => 'Kontenjan, sınıftaki öğrenci sayısından küçük olamaz.',
            ClassroomFailureReason::InvalidInput => 'Girilen sınıf bilgisi geçerli değil.',
            ClassroomFailureReason::Unauthorized => 'Bu işlem için yetkiniz yok.',
            ClassroomFailureReason::NotFound, ClassroomFailureReason::CrossInstitution => 'Not Found',
        };
    }

    private function account(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }

        return $user;
    }
}

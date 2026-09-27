<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\InstitutionTestAssignmentException;
use App\Service\InstitutionClassroomTestAssigner;
use App\Service\InstitutionDeliveryReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/ogretmen')]
#[IsGranted('ROLE_USER')]
final class TeacherClassroomController extends AbstractController
{
    public function __construct(
        private readonly InstitutionDeliveryReport $report,
        private readonly InstitutionClassroomTestAssigner $assigner,
    ) {
    }

    #[Route('/siniflarim', name: 'app_teacher_classrooms', methods: ['GET'])]
    public function index(): Response
    {
        $classrooms = $this->report->teacherClassrooms($this->account());
        if ([] === $classrooms) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->render('teacher/classrooms.html.twig', [
            'classrooms' => $classrooms,
        ]);
    }

    #[Route('/siniflar/{reference}/ata', name: 'app_teacher_test_assign', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function assign(Request $request, string $reference): Response
    {
        if (!$this->isCsrfTokenValid('teacher_test_assign', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $actor = $this->account();
        $institution = $this->report->teacherInstitution($actor, $reference);
        if (null === $institution) {
            throw new NotFoundHttpException('Not Found');
        }
        try {
            $this->assigner->createDraft(
                $actor,
                $institution,
                $request->request->getString('assessment'),
                $reference,
                $request->request->getString('opens_at'),
                $request->request->getString('closes_at'),
                $request->request->getString('instructions'),
            );
        } catch (InstitutionTestAssignmentException $exception) {
            if ('not_found' === $exception->getReason() || 'forbidden' === $exception->getReason()) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', match ($exception->getReason()) {
                'empty_class' => 'Bu sınıfta sınava atanabilecek aktif öğrenci bulunmuyor.',
                'overlap' => 'Bu sınıf ve dönem için bu testin açık bir ataması zaten var.',
                'window' => 'Başlangıç, bitişten önce olmalıdır.',
                default => 'Bu test sınıfa atanamıyor.',
            });

            return $this->redirectToRoute('app_teacher_classrooms');
        }
        $this->addFlash('success', 'Atama taslak olarak kaydedildi.');

        return $this->redirectToRoute('app_teacher_classrooms');
    }

    #[Route('/atamalar/{reference}/etkinlestir', name: 'app_teacher_test_activate', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function activate(Request $request, string $reference): Response
    {
        return $this->transition($request, $reference, 'teacher_test_activate', true);
    }

    #[Route('/atamalar/{reference}/kapat', name: 'app_teacher_test_close', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function close(Request $request, string $reference): Response
    {
        return $this->transition($request, $reference, 'teacher_test_close', false);
    }

    #[Route('/atamalar/{reference}/sonuclar', name: 'app_teacher_test_results', methods: ['GET'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function results(Request $request, string $reference): Response
    {
        $actor = $this->account();
        $delivery = $this->report->deliveryForTeacher($actor, strtolower($reference));
        if (null === $delivery) {
            throw new NotFoundHttpException('Not Found');
        }
        $page = $this->report->result($actor, $delivery, $request->query->getInt('page', 1));
        if (null === $page) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->render('teacher/test_results.html.twig', [
            'reference' => strtolower($reference),
            'report' => $page,
        ]);
    }

    private function transition(Request $request, string $reference, string $csrf, bool $activate): Response
    {
        if (!$this->isCsrfTokenValid($csrf, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $actor = $this->account();
        $delivery = $this->report->deliveryForTeacher($actor, strtolower($reference));
        if (null === $delivery) {
            throw new NotFoundHttpException('Not Found');
        }
        try {
            if ($activate) {
                $this->assigner->activate($actor, $delivery->getInstitution(), $reference);
            } else {
                $this->assigner->close($actor, $delivery->getInstitution(), $reference);
            }
        } catch (InstitutionTestAssignmentException $exception) {
            if ('not_found' === $exception->getReason() || 'forbidden' === $exception->getReason()) {
                throw new NotFoundHttpException('Not Found');
            }
            $this->addFlash('error', 'empty_class' === $exception->getReason()
                ? 'Bu sınıfta sınava atanabilecek aktif öğrenci bulunmuyor.'
                : 'Bu atama güncellenemiyor.');

            return $this->redirectToRoute('app_teacher_classrooms');
        }
        $this->addFlash('success', $activate ? 'Test sınıfa açıldı.' : 'Atama kapatıldı.');

        return $this->redirectToRoute('app_teacher_classrooms');
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

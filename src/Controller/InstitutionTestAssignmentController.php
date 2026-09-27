<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Assessment;
use App\Entity\Institution;
use App\Entity\User;
use App\Exception\InstitutionTestAssignmentException;
use App\Service\InstitutionClassroomTestAssigner;
use App\Service\InstitutionDeliveryReport;
use App\Service\InstitutionWorkspaceGate;
use App\Service\InstitutionWorkspaceQuery;
use App\Service\InvitationCodeDigestHasher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/kurum')]
#[IsGranted('ROLE_USER')]
final class InstitutionTestAssignmentController extends AbstractController
{
    public function __construct(
        private readonly InstitutionWorkspaceGate $gate,
        private readonly InstitutionWorkspaceQuery $query,
        private readonly InstitutionClassroomTestAssigner $assigner,
        private readonly InstitutionDeliveryReport $report,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvitationCodeDigestHasher $hasher,
    ) {
    }

    #[Route('/testler/{reference}/ata', name: 'app_institution_test_assign', methods: ['GET', 'POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function assign(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        $test = $this->query->test($institution, strtolower($reference));
        if (null === $test) {
            throw new NotFoundHttpException('Not Found');
        }
        $assessment = $this->assessment($institution, $reference);
        if ('POST' === $request->getMethod()) {
            if (!$this->isCsrfTokenValid('institution_test_assign', $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException('Geçersiz istek.');
            }
            try {
                $deliveryReference = $this->assigner->createDraft(
                    $this->account(),
                    $institution,
                    $reference,
                    $request->request->getString('classroom'),
                    $request->request->getString('opens_at'),
                    $request->request->getString('closes_at'),
                    $request->request->getString('instructions'),
                );
            } catch (InstitutionTestAssignmentException $exception) {
                return $this->failure($exception, 'app_institution_test_assign', ['reference' => $reference]);
            }
            $this->addFlash('success', 'Atama taslak olarak kaydedildi. Etkinleştirmek için onaylayın.');

            return $this->redirectToRoute('app_institution_test_assign', ['reference' => $reference, 'delivery' => $deliveryReference]);
        }

        return $this->render('institution/test_assign.html.twig', $this->frame('tests', [
            'test' => $test,
            'classrooms' => $this->report->classroomChoices($this->account(), $institution, $assessment),
            'assignments' => $this->report->assignments($institution, $assessment),
        ]));
    }

    #[Route('/test-atamalari/{reference}/etkinlestir', name: 'app_institution_test_activate', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function activate(Request $request, string $reference): Response
    {
        if (!$this->isCsrfTokenValid('institution_test_activate', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $institution = $this->institution();
        try {
            $this->assigner->activate($this->account(), $institution, $reference);
        } catch (InstitutionTestAssignmentException $exception) {
            return $this->failure($exception, 'app_institution_tests', []);
        }
        $this->addFlash('success', 'Test sınıfa açıldı.');

        return $this->redirectToRoute('app_institution_test_results', ['reference' => $reference]);
    }

    #[Route('/test-atamalari/{reference}/kapat', name: 'app_institution_test_close', methods: ['POST'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function close(Request $request, string $reference): Response
    {
        if (!$this->isCsrfTokenValid('institution_test_close', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Geçersiz istek.');
        }
        $institution = $this->institution();
        try {
            $this->assigner->close($this->account(), $institution, $reference);
        } catch (InstitutionTestAssignmentException $exception) {
            return $this->failure($exception, 'app_institution_tests', []);
        }
        $this->addFlash('success', 'Atama kapatıldı. Mevcut denemeler silinmedi.');

        return $this->redirectToRoute('app_institution_tests');
    }

    #[Route('/test-atamalari/{reference}/sonuclar', name: 'app_institution_test_results', methods: ['GET'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function results(Request $request, string $reference): Response
    {
        $institution = $this->institution();
        $delivery = $this->report->deliveryForInstitution($institution, strtolower($reference));
        if (null === $delivery) {
            throw new NotFoundHttpException('Not Found');
        }
        $page = $this->report->result($this->account(), $delivery, $request->query->getInt('page', 1));
        if (null === $page) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->render('institution/test_results.html.twig', $this->frame('tests', [
            'reference' => strtolower($reference),
            'report' => $page,
        ]));
    }

    private function assessment(Institution $institution, string $reference): Assessment
    {
        /** @var list<Assessment> $rows */
        $rows = $this->entityManager->getRepository(Assessment::class)->findBy(['institution' => $institution]);
        foreach ($rows as $assessment) {
            if (hash_equals($this->hasher->workspaceReference('assessment', $assessment->getId()), strtolower($reference))) {
                return $assessment;
            }
        }

        throw new NotFoundHttpException('Not Found');
    }

    /**
     * @param array<string, string> $params
     */
    private function failure(InstitutionTestAssignmentException $exception, string $route, array $params): Response
    {
        if ('not_found' === $exception->getReason()) {
            throw new NotFoundHttpException('Not Found');
        }
        if ('forbidden' === $exception->getReason()) {
            throw new AccessDeniedHttpException('Bu işlem için yetkiniz yok.');
        }
        $this->addFlash('error', match ($exception->getReason()) {
            'empty_class' => 'Bu sınıfta sınava atanabilecek aktif öğrenci bulunmuyor.',
            'overlap' => 'Bu sınıf ve dönem için bu testin açık bir ataması zaten var.',
            'window' => 'Başlangıç, bitişten önce olmalıdır.',
            default => 'Bu test sınıfa atanamıyor.',
        });

        return $this->redirectToRoute($route, $params);
    }

    private function institution(): Institution
    {
        $user = $this->account();
        $decision = $this->gate->resolve($user);
        if (InstitutionWorkspaceGate::PANEL !== $decision->outcome || null === $decision->selected) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }

        return $decision->selected->getInstitution();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function frame(string $nav, array $extra): array
    {
        $decision = $this->gate->resolve($this->account());
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

    private function account(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }

        return $user;
    }
}

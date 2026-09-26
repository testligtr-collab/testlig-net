<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\InstitutionWorkspaceDecision;
use App\Entity\Institution;
use App\Entity\User;
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

        return $this->render('institution/classrooms.html.twig', $this->frame($opened, 'classrooms', [
            'classrooms' => $this->query->classrooms($selected->getInstitution(), $this->pageNumber($request)),
        ]));
    }

    #[Route('/siniflar/{reference}', name: 'app_institution_classroom', methods: ['GET'], requirements: ['reference' => '[0-9a-f]{20}'])]
    public function classroom(string $reference): Response
    {
        $institution = $this->institution();
        $row = $this->query->classroom($institution, strtolower($reference));
        if (null === $row) {
            throw new NotFoundHttpException('Not Found');
        }

        return $this->render('institution/classroom.html.twig', $this->frame($this->gate->resolve($this->account()), 'classrooms', [
            'classroom' => $row,
        ]));
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

    private function account(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Bu sayfaya erişilemiyor.');
        }

        return $user;
    }
}

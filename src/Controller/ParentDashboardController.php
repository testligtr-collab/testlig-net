<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\ParentStudentLinkException;
use App\Service\ParentLinkQuery;
use App\Service\ParentStudentLinkCodeManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/veli')]
#[IsGranted('ROLE_PARENT')]
final class ParentDashboardController extends AbstractController
{
    public function __construct(
        private readonly ParentStudentLinkCodeManager $links,
        private readonly ParentLinkQuery $query,
    ) {
    }

    #[Route('', name: 'app_parent_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $parent = $this->parent();
        $children = $this->query->childrenForParent($parent);
        if ([] === $children) {
            return $this->redirectToRoute('app_parent_link');
        }

        return $this->render('parent/dashboard.html.twig', [
            'parent_name' => $parent->getFirstName(),
            'children' => $children,
        ]);
    }

    #[Route('/baglan', name: 'app_parent_link', methods: ['GET', 'POST'])]
    public function link(Request $request): Response
    {
        $parent = $this->parent();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('parent_link_redeem', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            try {
                $this->links->redeem($parent, (string) $request->request->get('code', ''), (string) $request->getClientIp());
            } catch (ParentStudentLinkException $exception) {
                return $this->fail($exception);
            }
            $this->addFlash('success', 'Bağlantı kuruldu.');

            return $this->redirectToRoute('app_parent_dashboard');
        }

        return $this->render('parent/link.html.twig', [
            'parent_name' => $parent->getFirstName(),
            'has_children' => [] !== $this->query->childrenForParent($parent),
        ]);
    }

    #[Route('/cocuklar', name: 'app_parent_children', methods: ['GET'])]
    public function children(): Response
    {
        $parent = $this->parent();

        return $this->render('parent/children.html.twig', [
            'parent_name' => $parent->getFirstName(),
            'children' => $this->query->childrenForParent($parent),
        ]);
    }

    #[Route('/cocuk/{reference}', name: 'app_parent_child', methods: ['GET'], requirements: ['reference' => '[a-f0-9]{20}'])]
    public function child(string $reference): Response
    {
        $parent = $this->parent();
        $child = $this->query->childForParent($parent, $reference);
        if (null === $child) {
            throw $this->createNotFoundException();
        }

        return $this->render('parent/child.html.twig', [
            'parent_name' => $parent->getFirstName(),
            'child' => $child,
        ]);
    }

    #[Route('/testler', name: 'app_parent_tests', methods: ['GET'])]
    public function tests(Request $request): Response
    {
        $parent = $this->parent();
        $children = $this->query->childrenForParent($parent);
        $reference = (string) $request->query->get('cocuk', '');
        if ('' === $reference && 1 === \count($children)) {
            $reference = $children[0]->reference;
        }
        $page = $request->query->getInt('sayfa', 1);
        $summary = '' === $reference ? null : $this->query->testsForParent($parent, $reference, $page);
        if ('' !== $reference && null === $summary) {
            throw $this->createNotFoundException();
        }

        return $this->render('parent/tests.html.twig', [
            'parent_name' => $parent->getFirstName(),
            'children' => $children,
            'summary' => $summary,
        ]);
    }

    private function parent(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function fail(ParentStudentLinkException $exception): Response
    {
        if ('Çok fazla deneme. Lütfen daha sonra tekrar deneyin.' === $exception->getMessage()) {
            throw new TooManyRequestsHttpException(null, $exception->getMessage());
        }
        if ('Bu veli–öğrenci işlemi için yetkiniz yok.' === $exception->getMessage()) {
            throw $this->createAccessDeniedException();
        }
        $this->addFlash('error', $exception->getMessage());

        return $this->redirectToRoute('app_parent_link');
    }
}

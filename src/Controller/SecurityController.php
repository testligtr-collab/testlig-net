<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\CatalogLoginReturn;
use App\Security\LoginFormAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class SecurityController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(private readonly CatalogLoginReturn $catalogReturn)
    {
    }

    #[Route('/giris', name: LoginFormAuthenticator::LOGIN_ROUTE, methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        $session = $request->getSession();
        $query = $request->query->all();
        $fromCatalog = $request->isMethod('GET') && \array_key_exists('unite', $query);

        $user = $this->getUser();
        if (null !== $user) {
            // Already signed in (for example a student on a public unit page): same validated return rules.
            $path = $user instanceof User && $fromCatalog && $this->catalogReturn->remember($session, $query['sinif'] ?? null, $query['ders'] ?? null, $query['unite'], $query['konu'] ?? null)
                ? $this->catalogReturn->consume($session, $user)
                : null;

            return null !== $path ? $this->redirect($path) : $this->redirectToRoute('app_account');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        if ($request->isMethod('GET')) {
            if ($fromCatalog) {
                // "Giriş yap" from a public catalog unit or topic: remember it, validated, for after the login.
                if ($this->catalogReturn->remember($session, $query['sinif'] ?? null, $query['ders'] ?? null, $query['unite'], $query['konu'] ?? null)) {
                    $this->removeTargetPath($session, 'main');
                }
            } elseif (null === $error) {
                // A plain /giris visit is an independent login. A failed attempt redirects here with an error
                // and keeps the remembered unit so the form can be corrected.
                $this->catalogReturn->discard($session);
            }
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $error,
            'resume_catalog' => $this->catalogReturn->hasPending($session),
        ]);
    }

    #[Route('/cikis', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Logout is handled by the security firewall.');
    }
}

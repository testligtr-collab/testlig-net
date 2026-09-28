<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalPageController extends AbstractController
{
    public const UPDATED_ON = '28 Eylül 2026';

    #[Route('/gizlilik', name: 'app_legal_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->page('legal/privacy.html.twig', 'Gizlilik ve Kişisel Verilerin Korunması');
    }

    #[Route('/kullanim-kosullari', name: 'app_legal_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->page('legal/terms.html.twig', 'Kullanım Koşulları');
    }

    #[Route('/cerez-politikasi', name: 'app_legal_cookies', methods: ['GET'])]
    public function cookies(): Response
    {
        return $this->page('legal/cookies.html.twig', 'Çerez Politikası');
    }

    #[Route('/cocuk-ve-veli-bilgilendirmesi', name: 'app_legal_children', methods: ['GET'])]
    public function children(): Response
    {
        return $this->page('legal/children.html.twig', 'Çocuklar ve Veliler İçin Bilgilendirme');
    }

    private function page(string $template, string $title): Response
    {
        return $this->render($template, [
            'legal_title' => $title,
            'legal_updated_on' => self::UPDATED_ON,
        ]);
    }
}

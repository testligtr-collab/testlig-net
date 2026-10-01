<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\GradeLevel;
use App\Service\PublicCatalogQuery;
use App\Service\PublicSitemapDocument;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Public published-catalog pages. HTML is identical for every visitor and does not read the session.
 */
final class PublicCatalogController extends AbstractController
{
    private const GRADE_PATTERN = '12|11|10|[1-9]';
    private const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public function __construct(
        private readonly PublicCatalogQuery $catalog,
    ) {
    }

    #[Route('/dersler', name: 'app_public_catalog', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page('public_catalog/index.html.twig', [
            'grades' => $this->catalog->grades(),
        ], 'Dersleri keşfet', 'Yayımlanmış sınıf ve dersleri giriş yapmadan inceleyin.', 'app_public_catalog');
    }

    #[Route('/dersler/{grade}', name: 'app_public_catalog_grade', methods: ['GET'], requirements: ['grade' => self::GRADE_PATTERN])]
    public function grade(string $grade): Response
    {
        $level = $this->level($grade);
        $subjects = $this->catalog->grade($level);
        if (null === $subjects) {
            throw $this->createNotFoundException();
        }

        return $this->page('public_catalog/grade.html.twig', [
            'grade' => $level->value,
            'grade_label' => $level->value.'. sınıf',
            'subjects' => $subjects,
        ], $level->value.'. sınıf dersleri', $level->value.'. sınıf için yayımlanmış dersler.', 'app_public_catalog_grade', ['grade' => $level->value]);
    }

    #[Route('/dersler/{grade}/{subjectSlug}', name: 'app_public_catalog_subject', methods: ['GET'], requirements: ['grade' => self::GRADE_PATTERN, 'subjectSlug' => self::SLUG_PATTERN])]
    public function subject(string $grade, string $subjectSlug): Response
    {
        $level = $this->level($grade);
        $page = $this->catalog->subject($level, $subjectSlug);
        if (null === $page) {
            throw $this->createNotFoundException();
        }
        $description = $page->subject->description ?? $level->value.'. sınıf '.$page->subject->name.' dersinin yayımlanmış üniteleri.';

        return $this->page('public_catalog/subject.html.twig', [
            'grade' => $level->value,
            'grade_label' => $level->value.'. sınıf',
            'subject' => $page->subject,
            'units' => $page->units,
        ], $level->value.'. sınıf '.$page->subject->name, $description, 'app_public_catalog_subject', [
            'grade' => $level->value,
            'subjectSlug' => $page->subject->slug,
        ]);
    }

    #[Route('/dersler/{grade}/{subjectSlug}/{unitSlug}', name: 'app_public_catalog_unit', methods: ['GET'], requirements: ['grade' => self::GRADE_PATTERN, 'subjectSlug' => self::SLUG_PATTERN, 'unitSlug' => self::SLUG_PATTERN])]
    public function unit(string $grade, string $subjectSlug, string $unitSlug): Response
    {
        $level = $this->level($grade);
        $page = $this->catalog->unit($level, $subjectSlug, $unitSlug);
        if (null === $page) {
            throw $this->createNotFoundException();
        }
        $description = $page->unit->description ?? $page->subject->name.' dersinde '.$page->unit->name.' ünitesinin yayımlanmış konu adları.';

        return $this->page('public_catalog/unit.html.twig', [
            'grade' => $level->value,
            'grade_label' => $level->value.'. sınıf',
            'subject' => $page->subject,
            'unit' => $page->unit,
            'topics' => $page->topics,
        ], $page->subject->name.': '.$page->unit->name, $description, 'app_public_catalog_unit', [
            'grade' => $level->value,
            'subjectSlug' => $page->subject->slug,
            'unitSlug' => $page->unit->slug,
        ]);
    }

    #[Route('/sitemap.xml', name: 'app_public_sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $urls = [
            ['loc' => $this->generateUrl('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL), 'lastmod' => null],
            ['loc' => $this->generateUrl('app_legal_privacy', [], UrlGeneratorInterface::ABSOLUTE_URL), 'lastmod' => null],
            ['loc' => $this->generateUrl('app_legal_terms', [], UrlGeneratorInterface::ABSOLUTE_URL), 'lastmod' => null],
            ['loc' => $this->generateUrl('app_legal_cookies', [], UrlGeneratorInterface::ABSOLUTE_URL), 'lastmod' => null],
            ['loc' => $this->generateUrl('app_legal_children', [], UrlGeneratorInterface::ABSOLUTE_URL), 'lastmod' => null],
            ['loc' => $this->generateUrl('app_public_catalog', [], UrlGeneratorInterface::ABSOLUTE_URL), 'lastmod' => null],
        ];
        foreach ($this->catalog->sitemapEntries() as $entry) {
            $urls[] = [
                'loc' => $this->absolute($entry->path),
                'lastmod' => $entry->lastModified?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
            ];
        }
        $response = new Response(PublicSitemapDocument::render($urls), Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
        $this->discoveryHeaders($response);

        return $response;
    }

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'])]
    public function robots(): Response
    {
        $sitemap = $this->generateUrl('app_public_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $body = "User-agent: *\nAllow: /\n\nSitemap: ".$sitemap."\n";
        $response = new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $this->discoveryHeaders($response);

        return $response;
    }

    /**
     * @param array<string, mixed>      $parameters
     * @param array<string, string|int> $routeParameters
     */
    private function page(string $template, array $parameters, string $title, string $description, string $route, array $routeParameters = []): Response
    {
        $parameters['catalog_title'] = $title;
        $parameters['catalog_description'] = $this->summary($description);
        $parameters['canonical_url'] = $this->generateUrl($route, $routeParameters, UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render($template, $parameters);
    }

    private function level(string $grade): GradeLevel
    {
        $level = GradeLevel::tryFrom((int) $grade);
        if (!$level instanceof GradeLevel) {
            throw $this->createNotFoundException();
        }

        return $level;
    }

    private function summary(string $text): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($clean) <= 160) {
            return $clean;
        }

        return mb_substr($clean, 0, 157).'…';
    }

    private function absolute(string $path): string
    {
        $home = $this->generateUrl('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return rtrim($home, '/').$path;
    }

    private function discoveryHeaders(Response $response): void
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}

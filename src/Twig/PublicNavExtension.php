<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Single source of the visitor-facing main navigation shared by every public
 * site shell (homepage, public pages, catalog, legal). Every item points at a
 * working named route; promo-only sections are intentionally not listed.
 */
final class PublicNavExtension extends AbstractExtension
{
    /**
     * @var list<array{id: string, label: string, route: string, prefix: string|null}>
     */
    private const ITEMS = [
        ['id' => 'home', 'label' => 'Ana Sayfa', 'route' => 'app_home', 'prefix' => null],
        ['id' => 'catalog', 'label' => 'Sınıflar', 'route' => 'app_public_catalog', 'prefix' => 'app_public_catalog'],
        ['id' => 'news', 'label' => 'Haberler', 'route' => 'app_news_index', 'prefix' => 'app_news_'],
    ];

    public function __construct(
        private readonly RequestStack $requests,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_nav_items', $this->forCurrentRequest(...)),
        ];
    }

    /**
     * @return list<array{id: string, label: string, route: string, current: bool}>
     */
    public function forCurrentRequest(): array
    {
        $route = $this->requests->getMainRequest()?->attributes->get('_route');

        return self::items(\is_string($route) ? $route : null);
    }

    /**
     * @return list<array{id: string, label: string, route: string, current: bool}>
     */
    public static function items(?string $currentRoute): array
    {
        $items = [];
        foreach (self::ITEMS as $item) {
            $prefix = $item['prefix'];
            $current = null !== $currentRoute && (
                null === $prefix
                    ? $currentRoute === $item['route']
                    : str_starts_with($currentRoute, $prefix)
            );
            $items[] = [
                'id' => $item['id'],
                'label' => $item['label'],
                'route' => $item['route'],
                'current' => $current,
            ];
        }

        return $items;
    }
}

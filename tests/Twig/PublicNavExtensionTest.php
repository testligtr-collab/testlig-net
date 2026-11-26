<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\PublicNavExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class PublicNavExtensionTest extends TestCase
{
    public function testItemsAreOnlyWorkingNamedRoutes(): void
    {
        $items = PublicNavExtension::items(null);

        self::assertSame(
            [
                ['id' => 'home', 'label' => 'Ana Sayfa', 'route' => 'app_home', 'current' => false],
                ['id' => 'catalog', 'label' => 'Sınıflar', 'route' => 'app_public_catalog', 'current' => false],
                ['id' => 'news', 'label' => 'Haberler', 'route' => 'app_news_index', 'current' => false],
            ],
            $items,
        );
    }

    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function currentRoutes(): iterable
    {
        yield 'home' => ['app_home', 'home'];
        yield 'catalog index' => ['app_public_catalog', 'catalog'];
        yield 'catalog subject' => ['app_public_catalog_subject', 'catalog'];
        yield 'news index' => ['app_news_index', 'news'];
        yield 'news article' => ['app_news_show', 'news'];
        yield 'login is not a nav item' => ['app_login', null];
        yield 'legal is not a nav item' => ['app_legal_privacy', null];
        yield 'no route' => [null, null];
    }

    #[DataProvider('currentRoutes')]
    public function testMarksAtMostTheMatchingItemAsCurrent(?string $route, ?string $expectedId): void
    {
        $current = array_values(array_map(
            static fn (array $item): string => $item['id'],
            array_filter(PublicNavExtension::items($route), static fn (array $item): bool => $item['current']),
        ));

        self::assertSame(null === $expectedId ? [] : [$expectedId], $current);
    }

    public function testReadsRouteFromMainRequest(): void
    {
        $stack = new RequestStack();
        $request = new Request();
        $request->attributes->set('_route', 'app_news_show');
        $stack->push($request);

        $extension = new PublicNavExtension($stack);
        $items = $extension->forCurrentRequest();

        self::assertTrue($items[2]['current']);
        self::assertFalse($items[0]['current']);
        self::assertSame('public_nav_items', $extension->getFunctions()[0]->getName());
    }

    public function testWithoutRequestNothingIsCurrent(): void
    {
        $items = (new PublicNavExtension(new RequestStack()))->forCurrentRequest();

        self::assertSame([false, false, false], array_column($items, 'current'));
    }
}

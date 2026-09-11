<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageReturnsHttpOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Öğren, çöz, gelişimini gör.');
        self::assertSelectorExists('a.skip-link');
        self::assertSelectorExists('header.site-header');
        self::assertSelectorTextContains('body', 'Testlig nasıl yardımcı olur?');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccessControlOrderTest extends WebTestCase
{
    public function testPublicAuthRoutesRemainPublic(): void
    {
        $client = static::createClient();
        foreach (['/kayit', '/giris', '/kayit/eposta-kontrol', '/kayit/dogrulama-yeniden', '/sifremi-unuttum', '/sifremi-unuttum/eposta-kontrol', '/sifre-yenile', '/health', '/'] as $path) {
            $client->request('GET', $path);
            self::assertTrue(
                $client->getResponse()->isSuccessful() || $client->getResponse()->isRedirection(),
                \sprintf('Expected public access for %s', $path)
            );
        }
    }

    public function testAccountRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/hesabim');
        self::assertResponseRedirects('/giris');

        $client->request('GET', '/hesabim/sifre-degistir');
        self::assertResponseRedirects('/giris');
    }
}

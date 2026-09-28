<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReleaseCandidateReadinessTest extends WebTestCase
{
    public function testPublicPagesStayIndexableAndSensitivePagesDoNot(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('noindex', (string) $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

        $client->request('GET', '/kayit');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertNull($client->getResponse()->headers->get('X-Robots-Tag'));

        $client->request('GET', '/kayit/ogrenci');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertNull($client->getResponse()->headers->get('X-Robots-Tag'));

        $client->request('GET', '/hesabim');
        self::assertResponseRedirects('/giris');
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');

        $client->request('GET', '/basvuru/kurum');
        self::assertResponseRedirects('/giris');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');

        $client->request('GET', '/sifremi-unuttum');
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
    }

    public function testMissingPageUsesTestligErrorWithoutInternals(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/boyle-bir-sayfa-yok-rc1');
        self::assertResponseStatusCodeSame(404);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Sayfa bulunamadı', $html);
        self::assertStringContainsString('Ana sayfa', $html);
        self::assertStringContainsString('>Giriş</a>', $html);
        self::assertStringNotContainsString('SQLSTATE', $html);
        self::assertStringNotContainsString('vendor/symfony', $html);
        self::assertStringNotContainsString('exception.message', $html);
    }
}

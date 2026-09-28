<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LegalPageControllerTest extends WebTestCase
{
    public function testLegalPagesArePublicIndexableDocuments(): void
    {
        $client = static::createClient();
        $pages = [
            '/gizlilik' => 'Gizlilik ve Kişisel Verilerin Korunması',
            '/kullanim-kosullari' => 'Kullanım Koşulları',
            '/cerez-politikasi' => 'Çerez Politikası',
            '/cocuk-ve-veli-bilgilendirmesi' => 'Çocuklar ve Veliler İçin Bilgilendirme',
        ];

        foreach ($pages as $path => $title) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $title);
            self::assertSelectorTextContains('body', 'Son güncelleme: 28 Eylül 2026');
            $response = $client->getResponse();
            self::assertNull($response->headers->get('X-Robots-Tag'));
            self::assertNull($response->headers->get('Set-Cookie'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
            self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
            $policy = (string) $response->headers->get('Content-Security-Policy');
            self::assertStringContainsString("default-src 'self'", $policy);
            self::assertStringNotContainsString('unsafe-inline', $policy);
            self::assertStringNotContainsString('unsafe-eval', $policy);
            self::assertStringNotContainsString('*', $policy);
            self::assertDoesNotMatchRegularExpression('/https:(?!\\/)/', $policy);
            $html = (string) $response->getContent();
            self::assertStringNotContainsString('[DOLDURULACAK]', $html);
            self::assertStringNotContainsString('MERSİS', $html);
            self::assertStringNotContainsString('VERBİS', $html);
            self::assertDoesNotMatchRegularExpression('/\son(click|submit|load)=/', $html);
            self::assertStringNotContainsString(' style=', $html);
            self::assertStringNotContainsString('gtag', $html);
            self::assertStringNotContainsString('consent', strtolower($html));
        }
    }

    public function testPrivateSurfacesStayNoindex(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertNull($client->getResponse()->headers->get('X-Robots-Tag'));
        foreach (['/giris', '/kayit', '/davet/ogretmen', '/hesabim'] as $path) {
            $client->request('GET', $path);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}

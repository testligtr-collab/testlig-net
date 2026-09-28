<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeaderPolicyTest extends WebTestCase
{
    public function testHomepageIsIndexableAndUsesEnforcingCsp(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNull($response->headers->get('X-Robots-Tag'));
        self::assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertDocumentHeaders($response->headers->get('Content-Security-Policy'), false);
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $html = (string) $response->getContent();
        self::assertStringContainsString('styles/app', $html);
        self::assertStringContainsString('nonce=', $html);
        self::assertStringNotContainsString('ga.jspm.io', $html);
        self::assertStringNotContainsString('onclick=', $html);
        self::assertStringNotContainsString('unsafe-inline', $html);
    }

    public function testAuthRegistrationAndPrivatePrefixesAreNoindex(): void
    {
        $client = static::createClient();
        foreach (['/giris', '/kayit', '/kayit/ogrenci', '/sifremi-unuttum'] as $path) {
            $client->request('GET', $path);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
            $this->assertDocumentHeaders($client->getResponse()->headers->get('Content-Security-Policy'), false);
        }

        foreach (['/hesabim', '/ogrenci', '/veli', '/ogretmen', '/kurum', '/yonetim', '/basvuru/ogretmen', '/davet/ogretmen', '/davet/ogrenci', '/sifre-yenile', '/dogrula/eposta'] as $path) {
            $client->request('GET', $path);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
            self::assertStringNotContainsString('token', (string) $client->getResponse()->headers->get('X-Robots-Tag'));
        }
    }

    public function testHealthAndMissingPageStayInTheirLane(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertNull($client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertNull($client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertNull($client->getResponse()->headers->get('X-Frame-Options'));
    }

    private function assertDocumentHeaders(?string $policy, bool $upgrade): void
    {
        self::assertNotNull($policy);
        self::assertStringContainsString("default-src 'self'", $policy);
        self::assertStringContainsString("object-src 'none'", $policy);
        self::assertStringContainsString("base-uri 'self'", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringContainsString("form-action 'self'", $policy);
        self::assertStringContainsString('https://www.youtube-nocookie.com', $policy);
        self::assertStringContainsString('https://player.vimeo.com', $policy);
        self::assertStringNotContainsString('https://www.youtube.com', $policy);
        self::assertStringNotContainsString('unsafe-inline', $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);
        self::assertStringNotContainsString("script-src 'self' *", $policy);
        if ($upgrade) {
            self::assertStringContainsString('upgrade-insecure-requests', $policy);
        } else {
            self::assertStringNotContainsString('upgrade-insecure-requests', $policy);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\HttpsUrl;
use PHPUnit\Framework\TestCase;

final class HttpsUrlTest extends TestCase
{
    public function testAcceptsOfficialMebPdfUrlWithUnicodePath(): void
    {
        $url = 'https://mufredat.meb.gov.tr/Dosyalar/20268149102521-Matematik%20(1-4)%20DÖP.pdf';
        self::assertTrue(HttpsUrl::isValid($url, 500));
    }

    public function testRejectsHttpAndCredentials(): void
    {
        self::assertFalse(HttpsUrl::isValid('http://mufredat.meb.gov.tr/x', 500));
        self::assertFalse(HttpsUrl::isValid('https://user:pass@mufredat.meb.gov.tr/x', 500));
    }
}

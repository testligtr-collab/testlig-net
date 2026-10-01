<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\EventSubscriber\ResponseSecurityPolicySubscriber;
use App\Security\ContentSecurityPolicy;
use PHPUnit\Framework\TestCase;

final class ContentSecurityPolicyTest extends TestCase
{
    public function testPolicyStaysStrictAndAllowsOnlyPrivacyVideoHosts(): void
    {
        $header = ContentSecurityPolicy::header(str_repeat('ab', 16), false);
        self::assertStringContainsString("default-src 'self'", $header);
        self::assertStringContainsString("object-src 'none'", $header);
        self::assertStringContainsString("base-uri 'self'", $header);
        self::assertStringContainsString("frame-ancestors 'none'", $header);
        self::assertStringContainsString("form-action 'self'", $header);
        self::assertStringContainsString('https://www.youtube-nocookie.com', $header);
        self::assertStringContainsString('https://player.vimeo.com', $header);
        self::assertStringNotContainsString('https://www.youtube.com', $header);
        self::assertStringNotContainsString('unsafe-inline', $header);
        self::assertStringNotContainsString('unsafe-eval', $header);
        self::assertStringNotContainsString('*', $header);
        self::assertStringNotContainsString('upgrade-insecure-requests', $header);

        $production = ContentSecurityPolicy::header(str_repeat('cd', 16), true);
        self::assertStringContainsString('upgrade-insecure-requests', $production);
    }

    public function testIndexableRoutesAreAnExplicitAllowlist(): void
    {
        self::assertSame([
            'app_home',
            'app_legal_privacy',
            'app_legal_terms',
            'app_legal_cookies',
            'app_legal_children',
            'app_public_catalog',
            'app_public_catalog_grade',
            'app_public_catalog_subject',
            'app_public_catalog_unit',
        ], ResponseSecurityPolicySubscriber::INDEXABLE_ROUTES);
    }
}

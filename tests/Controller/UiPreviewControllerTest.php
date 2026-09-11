<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

final class UiPreviewControllerTest extends WebTestCase
{
    public function testStudentPreviewIsAvailableInTestEnv(): void
    {
        $client = static::createClient();
        $client->request('GET', '/onizleme/ogrenci');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Günaydın Ece, bugünkü hedefin hazır.');
        self::assertSelectorTextContains('body', 'Çalışmaya Devam Et');
        self::assertSelectorExists('nav.bottom-nav');
        self::assertSelectorExists('aside.panel-sidebar');
    }

    public function testTeacherPreviewIsAvailableInTestEnv(): void
    {
        $client = static::createClient();
        $client->request('GET', '/onizleme/ogretmen');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Günaydın Deniz Öğretmen.');
        self::assertSelectorTextContains('body', 'Yeterli katılım oluştuğunda sınıf analizi gösterilir.');
    }

    public function testParentPreviewIsAvailableInTestEnv(): void
    {
        $client = static::createClient();
        $client->request('GET', '/onizleme/veli');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ece’nin haftası');
        self::assertSelectorTextContains('body', 'Destekleyici öneri');
    }

    public function testPreviewRoutesRejectMutatingMethods(): void
    {
        $client = static::createClient();
        foreach (['/onizleme/ogrenci', '/onizleme/ogretmen', '/onizleme/veli'] as $path) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $client->request($method, $path);
                self::assertResponseStatusCodeSame(405, \sprintf('%s %s', $method, $path));
            }
        }
    }

    public function testPreviewHtmlOmitsSecretsAndRealContactData(): void
    {
        $client = static::createClient();
        $client->request('GET', '/onizleme/ogrenci');
        $html = (string) $client->getResponse()->getContent();

        foreach ([
            '@gmail.com',
            '@hotmail.com',
            'ciphertext',
            'answerNonce',
            'answer_integrity_hmac',
            'Bearer ',
            'sk_live',
            'api.testlig',
            'correctStableKey',
        ] as $needle) {
            self::assertStringNotContainsStringIgnoringCase($needle, $html);
        }
    }

    public function testPreviewRoutesAbsentInProductionRouter(): void
    {
        $previewRoutes = (string) file_get_contents(\dirname(__DIR__, 2).'/config/routes/ui_preview.yaml');
        self::assertStringContainsString('when@dev:', $previewRoutes);
        self::assertStringContainsString('when@test:', $previewRoutes);
        self::assertStringNotContainsString('when@prod:', $previewRoutes);
        self::assertDirectoryDoesNotExist(\dirname(__DIR__, 2).'/src/Controller/UiPreview');
        self::assertDirectoryExists(\dirname(__DIR__, 2).'/src/UiPreview/Controller');

        $kernel = new Kernel('prod', false);
        $kernel->boot();
        try {
            /** @var RouterInterface $router */
            $router = $kernel->getContainer()->get('router');
            $collection = $router->getRouteCollection()->all();
            self::assertArrayNotHasKey('ui_preview_student', $collection);
            self::assertArrayNotHasKey('ui_preview_teacher', $collection);
            self::assertArrayNotHasKey('ui_preview_parent', $collection);
            foreach ($collection as $route) {
                self::assertStringNotContainsString('/onizleme', (string) $route->getPath());
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAccountStillRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/hesabim');
        self::assertResponseRedirects();
        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/giris', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testLogoutGetStillMethodNotAllowed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cikis');
        self::assertResponseStatusCodeSame(405);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ReleaseRevisionHeaderSubscriber;
use App\Release\ReleaseRevision;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ReleaseRevisionHeaderSubscriberTest extends TestCase
{
    private const SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    public function testMainResponseCarriesOnlyTheFullSha(): void
    {
        $response = new Response('<html></html>', 200, ['Cache-Control' => 'public, max-age=60', 'X-Content-Type-Options' => 'nosniff']);
        $before = $response->headers->all();

        $this->dispatch(self::SHA, $response);

        self::assertSame(self::SHA, $response->headers->get(ReleaseRevision::HEADER));
        self::assertSame([self::SHA], $response->headers->all('x-testlig-release'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<html></html>', $response->getContent());
        $after = $response->headers->all();
        unset($after['x-testlig-release']);
        self::assertSame($before['cache-control'], $after['cache-control']);
        self::assertSame($before['x-content-type-options'], $after['x-content-type-options']);
    }

    public function testRedirectsAndErrorsKeepTheirStatus(): void
    {
        foreach ([301, 302, 403, 404, 500] as $status) {
            $response = new Response('', $status);
            $this->dispatch(self::SHA, $response);
            self::assertSame($status, $response->getStatusCode());
            self::assertSame(self::SHA, $response->headers->get(ReleaseRevision::HEADER));
        }
    }

    public function testNoHeaderWithoutAValidRevision(): void
    {
        foreach (['', 'unknown', 'A1B2C3D4E5F60718293A4B5C6D7E8F9012345678', substr(self::SHA, 0, 12), self::SHA."\nX-Injected: 1"] as $value) {
            $response = new Response();
            $this->dispatch($value, $response);
            self::assertFalse($response->headers->has(ReleaseRevision::HEADER), $value);
        }
    }

    public function testSubRequestsAreIgnored(): void
    {
        $response = new Response();
        $this->dispatch(self::SHA, $response, HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($response->headers->has(ReleaseRevision::HEADER));
    }

    public function testSubscribesLateOnTheResponseEvent(): void
    {
        $events = ReleaseRevisionHeaderSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelResponse', -2048], $events[KernelEvents::RESPONSE]);
    }

    public function testRevisionIsNeverReadFromDiskDuringARequest(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/src/EventSubscriber/ReleaseRevisionHeaderSubscriber.php');

        foreach (['file_get_contents', 'fopen', 'readlink', 'realpath', 'REVISION', 'is_file', 'getenv', '$_SERVER', '$_ENV'] as $needle) {
            self::assertStringNotContainsString($needle, $source, $needle);
        }
    }

    private function dispatch(string $revision, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        (new ReleaseRevisionHeaderSubscriber($revision))->onKernelResponse(new ResponseEvent($kernel, Request::create('/'), $type, $response));
    }
}

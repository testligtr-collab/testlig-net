<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\CatalogLoginReturn;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CatalogLoginReturnTest extends KernelTestCase
{
    public function testFreshEntryIsPendingAndDiscardRemovesIt(): void
    {
        $session = $this->session(['grade' => 1, 'subject' => 'matematik', 'unit' => 'sayilar', 'topic' => null, 'at' => time()]);
        $service = $this->service();

        self::assertTrue($service->hasPending($session));
        $service->discard($session);
        self::assertFalse($service->hasPending($session));
    }

    public function testEntryOlderThanTheTimeToLiveIsIgnored(): void
    {
        $session = $this->session(['grade' => 1, 'subject' => 'matematik', 'unit' => 'sayilar', 'topic' => null, 'at' => time() - CatalogLoginReturn::TTL_SECONDS - 5]);

        self::assertFalse($this->service()->hasPending($session));
    }

    #[DataProvider('tamperedEntries')]
    public function testTamperedSessionEntriesAreIgnored(mixed $entry): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set(CatalogLoginReturn::SESSION_KEY, $entry);

        self::assertFalse($this->service()->hasPending($session));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function tamperedEntries(): iterable
    {
        $now = time();
        yield 'not an array' => ['/yonetim'];
        yield 'external unit' => [['grade' => 1, 'subject' => 'matematik', 'unit' => 'https://evil.example/', 'topic' => null, 'at' => $now]];
        yield 'traversal subject' => [['grade' => 1, 'subject' => '../yonetim', 'unit' => 'sayilar', 'topic' => null, 'at' => $now]];
        yield 'array topic' => [['grade' => 1, 'subject' => 'matematik', 'unit' => 'sayilar', 'topic' => ['x'], 'at' => $now]];
        yield 'string grade' => [['grade' => '1', 'subject' => 'matematik', 'unit' => 'sayilar', 'topic' => null, 'at' => $now]];
        yield 'missing time' => [['grade' => 1, 'subject' => 'matematik', 'unit' => 'sayilar', 'topic' => null]];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function session(array $entry): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set(CatalogLoginReturn::SESSION_KEY, $entry);

        return $session;
    }

    private function service(): CatalogLoginReturn
    {
        self::bootKernel();
        $service = static::getContainer()->get(CatalogLoginReturn::class);
        self::assertInstanceOf(CatalogLoginReturn::class, $service);

        return $service;
    }
}

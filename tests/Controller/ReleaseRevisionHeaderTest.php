<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Release\ReleaseRevision;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Local and CI checkouts carry no REVISION file, so there the header must be absent, never a made-up SHA.
 * A checkout that does have a valid one must serve exactly that value from the compiled container.
 */
final class ReleaseRevisionHeaderTest extends WebTestCase
{
    public function testCompiledParameterIsEmptyOrAFullSha(): void
    {
        self::bootKernel();
        $revision = static::getContainer()->getParameter('app.release_revision');

        self::assertIsString($revision);
        self::assertTrue('' === $revision || null !== ReleaseRevision::parse($revision), 'Compiled revision must be empty or a full SHA.');
    }

    public function testPublicPagesServeTheCompiledRevisionOrNone(): void
    {
        $client = static::createClient();
        $revision = static::getContainer()->getParameter('app.release_revision');
        self::assertIsString($revision);

        foreach (['/', '/giris', '/dersler'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            $values = $client->getResponse()->headers->all('x-testlig-release');
            self::assertSame('' === $revision ? [] : [$revision], $values, $path);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ApplicationSmokeTest extends KernelTestCase
{
    public function testApplicationBootsInTestEnvironment(): void
    {
        self::bootKernel(['environment' => 'test']);

        self::assertSame('test', self::$kernel?->getEnvironment());
        self::assertInstanceOf(Kernel::class, self::$kernel);
        self::assertTrue(self::getContainer()->has('kernel'));
    }
}

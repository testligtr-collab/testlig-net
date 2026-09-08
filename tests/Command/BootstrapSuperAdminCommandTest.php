<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Enum\UserRole;
use App\Exception\InvalidUserTransitionException;
use App\Service\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class BootstrapSuperAdminCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->cleanup();
    }

    public function testCommandFailsWithoutConfirm(): void
    {
        $tester = $this->tester();
        $tester->setInputs(["Guclu-Parola-123!\n"]);
        $status = $tester->execute([
            'command' => 'app:user:bootstrap-super-admin',
            '--email' => 'cli-sa@example.com',
        ], ['interactive' => true]);

        self::assertSame(1, $status);
        self::assertStringContainsString('--confirm', $tester->getDisplay());
        self::assertStringNotContainsString('Guclu-Parola-123!', $tester->getDisplay());
    }

    public function testCommandFailsWhenEnvDisabled(): void
    {
        $tester = $this->tester();
        $tester->setInputs(["Guclu-Parola-123!\n"]);
        $status = $tester->execute([
            'command' => 'app:user:bootstrap-super-admin',
            '--email' => 'cli-sa-disabled@example.com',
            '--confirm' => true,
        ], ['interactive' => true]);

        self::assertSame(1, $status);
        self::assertStringContainsString('disabled', strtolower($tester->getDisplay()));
        self::assertStringNotContainsString('Guclu-Parola-123!', $tester->getDisplay());
    }

    public function testCommandRejectsPasswordOptionStyleArgv(): void
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);
        $application = new Application($kernel);
        $command = $application->find('app:user:bootstrap-super-admin');
        $tester = new CommandTester($command);

        $previous = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['bin/console', 'app:user:bootstrap-super-admin', '--password=secret'];
        try {
            $status = $tester->execute([
                'command' => 'app:user:bootstrap-super-admin',
                '--email' => 'cli-sa-pwdarg@example.com',
                '--confirm' => true,
            ], ['interactive' => false]);
        } finally {
            $_SERVER['argv'] = $previous;
        }

        self::assertSame(1, $status);
        self::assertStringContainsString('CLI argument', $tester->getDisplay());
    }

    public function testFactoryStillCannotCreateSuperAdmin(): void
    {
        $factory = static::getContainer()->get(UserFactory::class);
        self::assertInstanceOf(UserFactory::class, $factory);
        $this->expectException(InvalidUserTransitionException::class);
        $factory->create('factory-sa@example.com', 'Guclu-Parola-123!', 'F', 'S', UserRole::SuperAdmin);
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);
        $application = new Application($kernel);

        return new CommandTester($application->find('app:user:bootstrap-super-admin'));
    }

    private function cleanup(): void
    {
        $connection = $this->em->getConnection();
        foreach (['security_audit_events', 'security_bootstrap_guards', 'reset_password_requests', 'users'] as $table) {
            if ($connection->createSchemaManager()->tablesExist([$table])) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }
}

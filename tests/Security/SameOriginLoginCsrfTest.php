<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SameOriginLoginCsrfTest extends KernelTestCase
{
    public function testPlaceholderAcceptedOnFirstSameOriginLogin(): void
    {
        self::assertTrue($this->validate('csrf-token', previousStrategy: null));
    }

    public function testShortInvalidTokenRejected(): void
    {
        self::assertFalse($this->validate('invalid-token', previousStrategy: null));
    }

    public function testPriorDoubleSubmitStrategyRejectsOriginOnlyPlaceholder(): void
    {
        // Bit 2: a previous unsafe request was accepted via double-submit.
        self::assertFalse($this->validate('csrf-token', previousStrategy: 2));
    }

    private function validate(string $value, ?int $previousStrategy): bool
    {
        self::bootKernel();
        $container = static::getContainer();
        $manager = $container->get('security.csrf.token_manager');
        $requestStack = $container->get('request_stack');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $manager);
        self::assertInstanceOf(RequestStack::class, $requestStack);

        $session = new Session(new MockArraySessionStorage());
        $session->start();
        if (null !== $previousStrategy) {
            $session->set('csrf-token', $previousStrategy);
        }

        $request = Request::create('https://testlig.test/giris', 'POST', [], [], [], [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'testlig.test',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ]);
        $request->setSession($session);
        $requestStack->push($request);

        try {
            return $manager->isTokenValid(new CsrfToken('authenticate', $value));
        } finally {
            self::ensureKernelShutdown();
        }
    }
}

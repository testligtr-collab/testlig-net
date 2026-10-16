<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\WebRuntimeIdentityProbe;
use App\Security\WebRuntimeIdentityReader;
use PHPUnit\Framework\TestCase;

final class WebRuntimeIdentityReaderTest extends TestCase
{
    public function testWebSapiReturnsOnlyNumericCredentials(): void
    {
        $reader = new WebRuntimeIdentityReader(new FakeWebRuntimeIdentityProbe(
            'lsapi',
            true,
            ['euid' => 42, 'egid' => 7, 'groups' => [7, 100]],
        ));

        self::assertSame([
            'nonce' => 'abcdef0123456789',
            'euid' => 42,
            'egid' => 7,
            'groups' => [7, 100],
        ], $reader->read('abcdef0123456789'));
    }

    public function testCliAndCliServerStayUnresolved(): void
    {
        $credentials = ['euid' => 1, 'egid' => 1, 'groups' => [1]];
        foreach (['cli', 'cli-server'] as $sapi) {
            $result = (new WebRuntimeIdentityReader(new FakeWebRuntimeIdentityProbe($sapi, true, $credentials)))
                ->read('abcdef0123456789');
            self::assertSame([
                'nonce' => 'abcdef0123456789',
                'status' => 'unresolved',
            ], $result);
            self::assertArrayNotHasKey('euid', $result);
        }
    }

    public function testMissingFunctionThrowAndInvalidCredentialsStayUnresolved(): void
    {
        $nonce = 'abcdef0123456789';
        $expected = ['nonce' => $nonce, 'status' => 'unresolved'];
        $cases = [
            new FakeWebRuntimeIdentityProbe('lsapi', false, ['euid' => 1, 'egid' => 1, 'groups' => [1]]),
            new FakeWebRuntimeIdentityProbe('lsapi', true, ['euid' => 1, 'egid' => 1, 'groups' => [1]], true),
            new FakeWebRuntimeIdentityProbe('lsapi', true, ['euid' => -1, 'egid' => 1, 'groups' => [1]]),
            new FakeWebRuntimeIdentityProbe('lsapi', true, ['euid' => 1, 'egid' => -1, 'groups' => [1]]),
            new FakeWebRuntimeIdentityProbe('lsapi', true, ['euid' => '1', 'egid' => 1, 'groups' => [1]]),
            new FakeWebRuntimeIdentityProbe('lsapi', true, ['euid' => 1, 'egid' => 1, 'groups' => ['1']]),
            new FakeWebRuntimeIdentityProbe('lsapi', true, ['euid' => 1, 'egid' => 1, 'groups' => range(0, WebRuntimeIdentityReader::GROUP_LIMIT)]),
            new FakeWebRuntimeIdentityProbe('lsapi', true, null),
        ];

        foreach ($cases as $probe) {
            $result = (new WebRuntimeIdentityReader($probe))->read($nonce);
            self::assertSame($expected, $result);
            self::assertArrayNotHasKey('euid', $result);
            self::assertArrayNotHasKey('groups', $result);
        }
    }

    public function testCurrentCliProcessIsNotWebEvidence(): void
    {
        self::assertSame('cli', \PHP_SAPI);
        $result = (new WebRuntimeIdentityReader(new \App\Security\PosixWebRuntimeIdentityProbe()))
            ->read('abcdef0123456789');
        self::assertSame([
            'nonce' => 'abcdef0123456789',
            'status' => 'unresolved',
        ], $result);
        self::assertArrayNotHasKey('euid', $result);
    }
}

final class FakeWebRuntimeIdentityProbe implements WebRuntimeIdentityProbe
{
    public function __construct(
        private readonly string $sapi,
        private readonly bool $available,
        private readonly mixed $credentials,
        private readonly bool $throws = false,
    ) {
    }

    public function sapi(): string
    {
        return $this->sapi;
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function credentials(): mixed
    {
        if ($this->throws) {
            throw new \RuntimeException('posix failed');
        }

        return $this->credentials;
    }
}
